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

    /** @var array<int,array<string,bool>> */
    private static array $memo = [];

    public static function forgetMemo(): void { self::$memo = []; }

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
        if ($key === null || !Schema::hasTable('held_leads') || self::isOn($companyId, $key)) {
            return false;
        }
        $company = Company::find($companyId);
        if ($company && !self::available($company, $key)) {
            return false;   // the feature lapsed (e.g. a complimentary period ended) — do not hold what the plan no longer covers
        }

        $now = now();
        $row = HeldLead::where('company_id', $companyId)->where('contact_id', $contactId)->where('source', $key)->where('status', 'held')->first();
        $fill = array_filter(array_intersect_key($attrs, array_flip(['origin_type', 'origin_id', 'origin_label', 'campaign_id', 'wa_open_campaign_id', 'flow_node_id', 'category', 'priority', 'notes'])), fn ($v) => $v !== null && $v !== '');
        if ($row) {
            $row->fill($fill)->forceFill(['hits' => $row->hits + 1, 'last_captured_at' => $now])->save();
        } else {
            HeldLead::create($fill + ['company_id' => $companyId, 'contact_id' => $contactId, 'source' => $key, 'status' => 'held', 'hits' => 1, 'first_captured_at' => $now, 'last_captured_at' => $now]);
        }
        return true;
    }
}
