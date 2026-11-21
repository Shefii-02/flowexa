<?php

namespace App\Modules\WaChat\Services;

use App\Models\Company;
use App\Models\MessageLog;
use App\Models\Wallet;
use App\Modules\Wallet\Exceptions\WalletException;
use App\Modules\Wallet\Services\WalletService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Pays for WA Chat (open-wa) messages from the company's wallet — one charge per message, taken when WhatsApp says it was DELIVERED.
 * Every WA Chat message — live chat, campaign, test message, API/OTP — leaves through the same gateway and is confirmed by the same
 * `message.ack` webhook, so that single event is where the money moves. WA Cloud is not handled here: it follows companies.wa_config.
 */
class WaChatBilling
{
    public const SOURCES = ['chat', 'campaign', 'api', 'test'];

    public function __construct(private readonly WalletService $wallet) {}

    /** Is this company charged per WA Chat message? */
    public function bills(Company $company): bool
    {
        return ($company->wa_chat_billing ?? 'wallet') === 'wallet' && $this->creditsPerMessage($company) > 0;
    }

    public function creditsPerMessage(Company $company): int
    {
        return max(0, (int) ($company->wa_chat_credits_per_message ?? 1));
    }

    /**
     * Charge one delivered message. Safe to call any number of times for the same message (a repeated webhook, delivered then read):
     * only the first call charges. Returns the credits taken (0 = nothing charged).
     */
    public function chargeDelivered(Company $company, ?string $sessionName, string $waMessageId, string $source = 'chat'): int
    {
        if (!$this->bills($company) || $waMessageId === '') { return 0; }

        // Only 1:1 messages we logged on send are billed — a group message has no log row and is not charged.
        $log = MessageLog::where('company_id', $company->id)->where('channel', 'wa_chat')->where('direction', 'outbound')->where('wa_message_id', $waMessageId)->first();
        if (!$log) { return 0; }

        $credits = $this->creditsPerMessage($company);
        // a message a campaign sent is recorded per recipient by the campaign job — that is how a campaign's messages are told apart from live chat
        if ($source === 'chat' && \App\Modules\WaChat\Models\WahaMessageLog::where('company_id', $company->id)->where('waha_message_id', $waMessageId)->exists()) { $source = 'campaign'; }

        try {
            return DB::transaction(function () use ($company, $sessionName, $waMessageId, $source, $log, $credits) {
                // The unique (company, message) key decides who charges: a second delivery event hits the duplicate and stops.
                $charge = DB::table('wa_chat_charges')->insertGetId([
                    'company_id' => $company->id, 'session_name' => $sessionName, 'wa_message_id' => $waMessageId, 'phone' => $log->phone,
                    'source' => in_array($source, self::SOURCES, true) ? $source : 'chat', 'credits' => $credits, 'status' => 'charged', 'created_at' => now(), 'updated_at' => now(),
                ]);

                $wallet = Wallet::where('company_id', $company->id)->lockForUpdate()->first();
                if (!$wallet || $wallet->balance < $credits) {
                    // Delivered with no credit left (e.g. a live chat message — those cannot be stopped in advance). Recorded, not lost.
                    DB::table('wa_chat_charges')->where('id', $charge)->update(['status' => 'unbilled']);
                    Log::warning("WaChatBilling: company {$company->id} message {$waMessageId} delivered with an empty wallet");
                    return 0;
                }

                $this->wallet->debit($company->id, $credits, "WA Chat message to {$log->phone}", $waMessageId, 'wa_chat_message');
                $tx = \App\Models\WalletTransaction::where('company_id', $company->id)->where('reference_type', 'wa_chat_message')->where('reference_id', $waMessageId)->latest('id')->value('id');
                DB::table('wa_chat_charges')->where('id', $charge)->update(['wallet_transaction_id' => $tx]);
                return $credits;
            });
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'Duplicate') || str_contains($e->getMessage(), 'UNIQUE') || ($e->errorInfo[1] ?? null) === 1062) { return 0; }   // already charged
            throw $e;
        } catch (WalletException $e) {
            Log::warning('WaChatBilling: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Why a send that goes through Laravel (campaign, test message, API/OTP) should be refused — null when it may go ahead.
     * $messages = how many messages it will send (a campaign's recipient count).
     */
    public function refusal(Company $company, int $messages = 1): ?string
    {
        if (!$this->bills($company)) { return null; }
        $need = $messages * $this->creditsPerMessage($company);
        $have = (int) (Wallet::where('company_id', $company->id)->value('balance') ?? 0);
        if ($have >= $need) { return null; }
        return $messages > 1
            ? "Not enough wallet credits: this sends {$messages} messages ({$need} credits) and the wallet has {$have}. Recharge the wallet and try again."
            : "Your wallet is out of credits ({$have} left, {$need} needed). Recharge the wallet to send WA Chat messages.";
    }
}
