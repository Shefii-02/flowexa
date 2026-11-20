<?php

namespace App\Services;

use App\Models\Company;
use App\Models\HeldLead;
use App\Support\Entitlements;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Switches for incoming lead capture, per source. A source that is OFF does not create leads: the contact is kept in the
 * holding area (held_leads) and staff move it into Leads — one by one or in bulk — when they want it.
 *
 * Which sources can be switched, and the plan feature each needs (a feature counts while it is in the plan OR is a
 * complimentary one that has not expired — Entitlements already applies that validity).
 */
class LeadSourceGate
{
    /** source => [label, icon, plan module or null when only "Leads" is needed] */
    public const SOURCES = [
        'wa_chat'        => ['WA Chat',             '💬', 'wa_chat'],
        'whatsapp_cloud' => ['WhatsApp Cloud API',  '☁️', 'wa_cloud'],
        'instagram'      => ['Instagram',           '📸', 'social_dm'],
        'website'        => ['Website chat widget', '🌐', 'web_widgets'],
        'meta_ads'       => ['Meta Ads',            '📣', 'meta_ads'],
        'flow'           => ['Flow Builder (auto)', '🔀', null],
    ];

    /** source => the leads.origin_type of its accounts (what can be switched individually) */
    public const ACCOUNT_TYPES = [
        'wa_chat'        => 'wa_session',
        'whatsapp_cloud' => 'phone_number',
        'instagram'      => 'instagram_account',
        'website'        => 'widget',
        'meta_ads'       => 'meta_ads_account',
    ];

    /** source => the leads.origin_type of a finer level inside an account that can also be switched by hand (a Meta ad campaign) */
    public const CAMPAIGN_TYPES = ['meta_ads' => 'ad_campaign'];

    /** @var array<int,array<string,bool>> */
    private static array $memo = [];
    /** @var array<int,array<string,bool>> company => "type:id" => enabled */
    private static array $acctMemo = [];
    private static ?bool $heldHasAccount = null;

    public static function forgetMemo(): void { self::$memo = []; self::$acctMemo = []; self::$heldHasAccount = null; }

    /** Is this single account (WA session / phone number / Instagram account / widget / ad account) switched on? No row = on. */
    public static function accountOn(int $companyId, string $type, int $id): bool
    {
        if (!Schema::hasTable('lead_account_switches')) {
            return true;
        }
        if (!isset(self::$acctMemo[$companyId])) {
            self::$acctMemo[$companyId] = DB::table('lead_account_switches')->where('company_id', $companyId)->get()
                ->mapWithKeys(fn ($r) => ["{$r->origin_type}:{$r->origin_id}" => (bool) $r->is_enabled])->all();
        }
        return self::$acctMemo[$companyId]["{$type}:{$id}"] ?? true;
    }

    /** The account a lead came through: an explicit account_type/account_id (Meta Ads — its origin is the campaign), else the origin when that is this source's account type. @return array{0:string,1:int}|null */
    private static function accountOf(string $source, array $attrs): ?array
    {
        $type = self::ACCOUNT_TYPES[$source] ?? null;
        if ($type === null) { return null; }
        if (($attrs['account_type'] ?? null) === $type && !empty($attrs['account_id'])) { return [$type, (int) $attrs['account_id']]; }
        if (($attrs['origin_type'] ?? null) === $type && !empty($attrs['origin_id'])) { return [$type, (int) $attrs['origin_id']]; }
        return null;
    }

    /** Names a channel writes → the gated source it belongs to, or null when that source cannot be switched. */
    public static function canonical(?string $source): ?string
    {
        return match ($source) {
            'wa_chat' => 'wa_chat',
            'whatsapp_cloud' => 'whatsapp_cloud',
            'instagram', 'instagram_dm' => 'instagram',
            'website', 'website_widget', 'widget' => 'website',
            'meta_ads' => 'meta_ads',
            'flow' => 'flow',
            default => null,
        };
    }

    public static function available(Company $company, string $source): bool
    {
        $module = self::SOURCES[$source][2] ?? null;
        return $module === null ? Entitlements::has($company, 'leads') : Entitlements::has($company, $module);
    }

    /** Is this source switched on? (no row = on; a source whose feature is not available is left alone — it cannot deliver anyway) */
    public static function isOn(int $companyId, string $source): bool
    {
        if (!Schema::hasTable('lead_source_settings')) {
            return true;
        }
        if (!isset(self::$memo[$companyId])) {
            self::$memo[$companyId] = DB::table('lead_source_settings')->where('company_id', $companyId)->pluck('is_enabled', 'source')->map(fn ($v) => (bool) $v)->all();
        }
        return self::$memo[$companyId][$source] ?? true;
    }

    /**
     * Call right before creating a lead from an incoming channel.
     * Returns true when the source is OFF — the contact was kept in the holding area and the caller must NOT create the lead.
     *
     * @param array<string,mixed> $attrs origin_type / origin_id / origin_label / campaign_id / wa_open_campaign_id / flow_node_id / category / priority / notes
     */
    public static function intercept(int $companyId, int $contactId, ?string $source, array $attrs = []): bool
    {
        $key = self::canonical($source);
        if ($key === null || !Schema::hasTable('held_leads')) {
            return false;
        }
        $sourceOff = !self::isOn($companyId, $key);
        $acct = self::accountOf($key, $attrs);
        $accountOff = $acct !== null && !self::accountOn($companyId, $acct[0], $acct[1]);
        // a single campaign inside an account (Meta Ads): the lead's origin IS the campaign
        $campType = self::CAMPAIGN_TYPES[$key] ?? null;
        $campaignOff = $campType !== null && ($attrs['origin_type'] ?? null) === $campType && !empty($attrs['origin_id'])
            && !self::accountOn($companyId, $campType, (int) $attrs['origin_id']);
        if (!$sourceOff && !$accountOff && !$campaignOff) {
            return false;
        }
        $company = Company::find($companyId);
        if ($company && !self::available($company, $key)) {
            return false;   // the feature lapsed (e.g. a complimentary period ended) — do not hold what the plan no longer covers
        }

        $now = now();
        $row = HeldLead::where('company_id', $companyId)->where('contact_id', $contactId)->where('source', $key)->where('status', 'held')->first();
        $fill = array_filter(array_intersect_key($attrs, array_flip(['origin_type', 'origin_id', 'origin_label', 'campaign_id', 'wa_open_campaign_id', 'flow_node_id', 'category', 'priority', 'notes'])), fn ($v) => $v !== null && $v !== '');
        self::$heldHasAccount ??= Schema::hasColumn('held_leads', 'held_reason');
        if (self::$heldHasAccount) {
            $fill += ['held_reason' => $sourceOff ? 'source_off' : ($accountOff ? 'account_off' : 'campaign_off')] + ($acct ? ['account_type' => $acct[0], 'account_id' => $acct[1]] : []);
        }
        if ($row) {
            $row->fill($fill)->forceFill(['hits' => $row->hits + 1, 'last_captured_at' => $now])->save();
        } else {
            HeldLead::create($fill + ['company_id' => $companyId, 'contact_id' => $contactId, 'source' => $key, 'status' => 'held', 'hits' => 1, 'first_captured_at' => $now, 'last_captured_at' => $now]);
        }
        return true;
    }
}
