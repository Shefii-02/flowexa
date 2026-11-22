<?php

namespace App\Modules\WaChat\Services;

use App\Models\Company;
use App\Models\Wallet;
use App\Modules\Wallet\Exceptions\WalletException;
use App\Modules\Wallet\Services\WalletService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Pays for WA Chat (open-wa) CAMPAIGN, API/OTP and TEST messages from the company's wallet — one charge per message, taken when
 * WhatsApp confirms it was DELIVERED. Normal chat messages (typed by staff, AI and chatbot replies) are free and never touch this.
 *
 * How a message becomes billable: the Laravel-run sends (campaign job, API/OTP, test) tag themselves with OpenWaMessageService::billedAs()
 * and register the gateway's message id here as "pending". The gateway's `message.ack` webhook then settles it. A message nobody
 * registered — i.e. live chat — is simply not found, so it is not charged. WA Cloud is separate and follows companies.wa_config.
 *
 * The credit rate comes from the company's PLAN (plans.wa_chat_credits_per_message; blank = 1, 0 = free); a superadmin can override it per company.
 */
class WaChatBilling
{
    public const SOURCES = ['campaign', 'api', 'test'];

    public function __construct(private readonly WalletService $wallet) {}

    /** Is this company charged for WA Chat campaign / API / test messages? */
    public function bills(Company $company): bool
    {
        return ($company->wa_chat_billing ?? 'wallet') === 'wallet' && $this->creditsPerMessage($company) > 0;
    }

    /** Credits per delivered message: the company's own override, else its plan's rate, else 1. */
    public function creditsPerMessage(Company $company): int
    {
        if ($company->wa_chat_credits_per_message !== null) { return max(0, (int) $company->wa_chat_credits_per_message); }
        return $this->planRate($company);
    }

    /** What the company's plan charges per delivered message (blank on the plan = 1). */
    public function planRate(Company $company): int
    {
        $plan = $company->plan;
        return max(0, (int) (($plan && $plan->wa_chat_credits_per_message !== null) ? $plan->wa_chat_credits_per_message : 1));
    }

    /** Called when a campaign / API / test message has been handed to the gateway: it will be charged once it is delivered. */
    public function registerPending(int $companyId, ?string $sessionName, string $waMessageId, ?string $phone, string $source): void
    {
        if ($waMessageId === '' || !in_array($source, self::SOURCES, true)) { return; }
        $company = Company::with('plan')->find($companyId);
        if (!$company || !$this->bills($company)) { return; }
        DB::table('wa_chat_charges')->insertOrIgnore([
            'company_id' => $companyId, 'session_name' => $sessionName, 'wa_message_id' => $waMessageId, 'phone' => $phone ? preg_replace('/@.*/', '', $phone) : null,
            'source' => $source, 'credits' => $this->creditsPerMessage($company), 'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);

        // The delivery report can beat this registration by a moment; if the message is already marked delivered, settle it now.
        $delivered = \App\Models\MessageLog::where('company_id', $companyId)->where('channel', 'wa_chat')->where('direction', 'outbound')->where('wa_message_id', $waMessageId)->whereNotNull('delivered_at')->exists();
        if ($delivered) { $this->chargeDelivered($company, $sessionName, $waMessageId); }
    }

    /**
     * Settle a delivered message. Safe to call any number of times for the same message (a repeated webhook, delivered then read):
     * only the call that moves the row out of "pending" charges. Returns the credits taken (0 = nothing charged).
     */
    public function chargeDelivered(Company $company, ?string $sessionName, string $waMessageId): int
    {
        if ($waMessageId === '') { return 0; }

        // Claim the row: exactly one caller flips pending → charging, everyone else (and live chat, which has no row) gets 0 here.
        $claimed = DB::table('wa_chat_charges')->where('company_id', $company->id)->where('wa_message_id', $waMessageId)->where('status', 'pending')->update(['status' => 'charging', 'updated_at' => now()]);
        if ($claimed !== 1) { return 0; }

        $row     = DB::table('wa_chat_charges')->where('company_id', $company->id)->where('wa_message_id', $waMessageId)->first();
        $credits = (int) $row->credits;

        try {
            return DB::transaction(function () use ($company, $row, $credits, $waMessageId) {
                $wallet = Wallet::where('company_id', $company->id)->lockForUpdate()->first();
                if (!$wallet || $wallet->balance < $credits) {
                    // Delivered after the wallet ran out (a big campaign, or deliveries that lagged behind the up-front check). Recorded, not lost.
                    DB::table('wa_chat_charges')->where('id', $row->id)->update(['status' => 'unbilled', 'updated_at' => now()]);
                    Log::warning("WaChatBilling: company {$company->id} {$row->source} message {$waMessageId} delivered with too little wallet credit");
                    return 0;
                }

                $label = ['campaign' => 'WA Chat campaign message', 'api' => 'WA Chat API message', 'test' => 'WA Chat test message'][$row->source] ?? 'WA Chat message';
                $this->wallet->debit($company->id, $credits, "{$label} to {$row->phone}", $waMessageId, 'wa_chat_message');
                $tx = \App\Models\WalletTransaction::where('company_id', $company->id)->where('reference_type', 'wa_chat_message')->where('reference_id', $waMessageId)->latest('id')->value('id');
                DB::table('wa_chat_charges')->where('id', $row->id)->update(['status' => 'charged', 'wallet_transaction_id' => $tx, 'updated_at' => now()]);
                \App\Models\MessageLog::where('company_id', $company->id)->where('channel', 'wa_chat')->where('direction', 'outbound')->where('wa_message_id', $waMessageId)->update(['cost' => min(255, $credits)]);   // Message Logs show what it cost
                return $credits;
            });
        } catch (WalletException $e) {
            DB::table('wa_chat_charges')->where('id', $row->id)->update(['status' => 'unbilled', 'updated_at' => now()]);
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
            : "Your wallet is out of credits ({$have} left, {$need} needed). Recharge the wallet to send WA Chat campaign and API messages.";
    }
}
