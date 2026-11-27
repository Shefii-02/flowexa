<?php

namespace App\Modules\WaCloud\Services;

use App\Models\Campaign;
use App\Models\CampaignContact;
use App\Models\Company;
use App\Models\MessageLog;
use App\Models\Wallet;
use App\Modules\Wallet\Exceptions\WalletException;
use App\Modules\Wallet\Services\WalletService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Pays for WA Cloud campaign and inbox-template messages from the wallet: delivered messages × the company's rate for the message's
 * Meta pricing category. The rate is Meta's rate + GST unless a superadmin overrode it on the company. Charged when WhatsApp reports the message
 * DELIVERED (Meta's own `billable` flag decides whether it is charged at all), never at launch — failed / undelivered messages cost nothing.
 * Own-account companies (Meta bills them directly) are never charged here.
 */
class WaCloudBilling
{
    public const CATEGORIES = ['marketing', 'utility', 'authentication', 'service'];

    public function __construct(private readonly WalletService $wallet) {}

    /** The defaults every company starts from. */
    public function defaults(): array
    {
        return ['rates' => config('wa_cloud_rates.rates'), 'gst_percent' => (float) config('wa_cloud_rates.gst_percent'), 'inr_per_credit' => (float) config('wa_cloud_rates.inr_per_credit')];
    }

    /** This company's effective rate card: its overrides laid over the defaults. */
    public function rateCard(Company $company): array
    {
        $d = $this->defaults();
        $over = is_array($company->wa_cloud_rates) ? $company->wa_cloud_rates : [];
        $rates = [];
        foreach (self::CATEGORIES as $c) { $rates[$c] = isset($over[$c]) && $over[$c] !== '' ? max(0.0, (float) $over[$c]) : (float) ($d['rates'][$c] ?? 0); }
        $gst = $company->wa_cloud_gst_percent !== null ? (float) $company->wa_cloud_gst_percent : $d['gst_percent'];

        return ['rates' => $rates, 'gst_percent' => $gst, 'inr_per_credit' => $d['inr_per_credit'], 'overridden' => array_keys(array_filter($over, fn ($v) => $v !== null && $v !== ''))];
    }

    /** What one delivered message of this category costs this company. */
    public function price(Company $company, string $category): array
    {
        $card = $this->rateCard($company);
        $category = strtolower($category);
        $base = (float) ($card['rates'][$category] ?? $card['rates']['marketing']);
        $total = $base * (1 + $card['gst_percent'] / 100);
        $credits = $base <= 0 ? 0 : max(1, (int) round($total / max(0.0001, $card['inr_per_credit'])));

        return ['category' => $category, 'rate_inr' => round($base, 4), 'gst_percent' => $card['gst_percent'], 'total_inr' => round($total, 4), 'credits' => $credits];
    }

    /** Is this company charged per delivered message from the wallet? */
    public function bills(Company $company): bool
    {
        return $this->wallet->usesWallet($company);
    }

    /** Credits a launch of $messages messages of a template category may cost — used to refuse a campaign the wallet clearly can't cover. */
    public function estimate(Company $company, ?string $templateCategory, int $messages): int
    {
        return $this->price($company, $templateCategory ?: 'marketing')['credits'] * $messages;
    }

    /**
     * Settle one delivered message. Safe to call repeatedly for the same message. Only campaign messages and inbox templates are charged
     * here (OTP is charged on send elsewhere). Returns credits taken.
     */
    public function chargeDelivered(Company $company, string $waMessageId, ?string $metaCategory, ?bool $metaBillable): int
    {
        if ($waMessageId === '' || ! $this->bills($company)) { return 0; }

        $cc = CampaignContact::where('wa_message_id', $waMessageId)->first();
        $source = $cc ? 'campaign' : (MessageLog::where('company_id', $company->id)->where('wa_message_id', $waMessageId)->where('source', 'inbox')->exists() ? 'inbox' : null);
        if ($source === null) { return 0; }

        $category = strtolower($metaCategory ?: ($cc?->pricing_category ?: 'marketing'));
        $free = $metaBillable === false || $category === 'service';
        $p = $this->price($company, $category);
        $credits = $free ? 0 : $p['credits'];

        $created = DB::table('wa_cloud_charges')->insertOrIgnore([
            'company_id' => $company->id, 'wa_message_id' => $waMessageId, 'campaign_id' => $cc?->campaign_id, 'source' => $source, 'category' => $category,
            'rate_inr' => $free ? 0 : $p['rate_inr'], 'gst_percent' => $p['gst_percent'], 'total_inr' => $free ? 0 : $p['total_inr'], 'credits' => $credits,
            'status' => $credits > 0 ? 'charging' : 'free', 'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($created !== 1 || $credits <= 0) { return 0; }   // already settled (or nothing to charge)

        try {
            return DB::transaction(function () use ($company, $waMessageId, $credits, $category, $cc, $source) {
                $wallet = Wallet::where('company_id', $company->id)->lockForUpdate()->first();
                if (! $wallet || $wallet->balance < $credits) {
                    DB::table('wa_cloud_charges')->where('company_id', $company->id)->where('wa_message_id', $waMessageId)->update(['status' => 'unbilled', 'updated_at' => now()]);
                    Log::warning("WaCloudBilling: company {$company->id} message {$waMessageId} delivered with too little wallet credit");
                    return 0;
                }
                $this->wallet->debit($company->id, $credits, "WA Cloud {$category} message delivered", $waMessageId, 'wa_cloud_message');
                $tx = \App\Models\WalletTransaction::where('company_id', $company->id)->where('reference_type', 'wa_cloud_message')->where('reference_id', $waMessageId)->latest('id')->value('id');
                DB::table('wa_cloud_charges')->where('company_id', $company->id)->where('wa_message_id', $waMessageId)->update(['status' => 'charged', 'wallet_transaction_id' => $tx, 'updated_at' => now()]);
                MessageLog::where('company_id', $company->id)->where('wa_message_id', $waMessageId)->update(['cost' => min(255, $credits)]);
                if ($cc) { Campaign::where('id', $cc->campaign_id)->increment('wallet_debited', $credits); }

                return $credits;
            });
        } catch (WalletException $e) {
            DB::table('wa_cloud_charges')->where('company_id', $company->id)->where('wa_message_id', $waMessageId)->update(['status' => 'unbilled', 'updated_at' => now()]);
            Log::warning('WaCloudBilling: ' . $e->getMessage());
            return 0;
        }
    }
}
