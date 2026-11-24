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
    /** @var array<int,array<string,string>> company => source => off|smart|all */
    private static array $reviewMemo = [];
    /** @var array<int,array<string,bool>> company => "type:id" => enabled */
    private static array $acctMemo = [];
    private static ?bool $heldHasAccount = null;

    public static function forgetMemo(): void { self::$reviewMemo = []; self::$memo = []; self::$acctMemo = []; self::$heldHasAccount = null; }

    /** What happens to a NEW contact on this source: off = lead at once | smart = check the conversation first | all = a team head reviews every one. */
    public static function reviewMode(int $companyId, string $source): string
    {
        if (!Schema::hasTable('lead_source_settings') || !Schema::hasColumn('lead_source_settings', 'review_mode')) {
            return 'off';
        }
        if (!isset(self::$reviewMemo[$companyId])) {
            self::$reviewMemo[$companyId] = DB::table('lead_source_settings')->where('company_id', $companyId)->pluck('review_mode', 'source')->all();
        }
        return self::$reviewMemo[$companyId][$source] ?? 'off';
    }

    /** The customer's own recent messages on this channel (oldest → newest), for the keyword check. Empty when the channel keeps none here. @return string[] */
    private static function inboundTexts(int $companyId, int $contactId, string $source): array
    {
        try {
            if ($source === 'website' && Schema::hasTable('widget_messages')) {
                return DB::table('widget_messages as m')->join('widget_conversations as c', 'c.id', '=', 'm.widget_conversation_id')
                    ->where('c.contact_id', $contactId)->where('m.role', 'visitor')->orderByDesc('m.id')->limit(8)->pluck('m.text')->reverse()->values()->all();
            }
            if ($source === 'instagram' && Schema::hasTable('instagram_messages')) {
                return DB::table('instagram_messages as m')->join('instagram_conversations as c', 'c.id', '=', 'm.instagram_conversation_id')
                    ->where('c.contact_id', $contactId)->where('m.direction', 'in')->whereNotNull('m.text')->orderByDesc('m.id')->limit(8)->pluck('m.text')->reverse()->values()->all();
            }
            if ($source === 'whatsapp_cloud' && Schema::hasTable('wa_messages')) {
                return DB::table('wa_messages as m')->join('wa_conversations as c', 'c.id', '=', 'm.conversation_id')
                    ->where('m.company_id', $companyId)->where('c.contact_id', $contactId)->where('m.direction', 'inbound')->where('m.type', 'text')
                    ->orderByDesc('m.id')->limit(8)->pluck('m.content')->reverse()
                    ->map(function ($c) { $a = is_array($c) ? $c : (json_decode((string) $c, true) ?: []); return (string) ($a['body'] ?? $a['text']['body'] ?? (is_string($a['text'] ?? null) ? $a['text'] : '')); })
                    ->filter()->values()->all();
            }
        } catch (\Throwable) {
            // a missing / different table must never stop a lead from being captured
        }
        return [];
    }

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
        $review = [];   // set when the source is ON but its new contacts wait for a team head
        if (!$sourceOff && !$accountOff && !$campaignOff) {
            $mode = self::reviewMode($companyId, $key);
            if ($mode === 'off') {
                return false;
            }
            if ($mode === 'smart') {
                if (in_array($key, ['meta_ads', 'flow'], true)) {
                    return false;   // a lead-form / flow contact is an enquiry by definition — nothing to check
                }
                $texts = self::inboundTexts($companyId, $contactId, $key);
                if ($key === 'wa_chat' || !$texts) {
                    // WA Chat keeps its messages on the phone gateway; its conversation label (when one exists) stands in for the text check
                    $t = \App\Models\ConversationTopic::where('company_id', $companyId)->where('contact_id', $contactId)->orderByDesc('classified_at')->first();
                    if ($t) { $review = ['topic' => $t->topic, 'topic_confidence' => $t->confidence, 'sample_text' => null, 'confident' => $t->confidence >= 0.55]; }
                } else {
                    $r = \App\Services\Topics\TopicRules::classify($texts);
                    $review = ['topic' => $r['topic'], 'topic_confidence' => $r['confidence'], 'sample_text' => mb_substr((string) end($texts), 0, 400), 'confident' => $r['confident']];
                }
                if (($review['topic'] ?? null) === 'lead_enquiry' && ($review['confident'] ?? false)) {
                    // it reads like a real enquiry: no waiting — and clear any earlier hold for this contact
                    HeldLead::where('company_id', $companyId)->where('contact_id', $contactId)->where('source', $key)->where('status', 'held')->where('held_reason', 'needs_review')
                        ->update(['status' => 'moved', 'handled_at' => now(), 'result_note' => 'The conversation turned into a lead enquiry — lead created automatically.']);
                    return false;
                }
            }
            $review = ['reason' => 'needs_review'] + $review;
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
            $fill += ['held_reason' => $review ? 'needs_review' : ($sourceOff ? 'source_off' : ($accountOff ? 'account_off' : 'campaign_off'))] + ($acct ? ['account_type' => $acct[0], 'account_id' => $acct[1]] : []);
            if ($review && Schema::hasColumn('held_leads', 'topic')) {
                $fill += array_filter(array_intersect_key($review, array_flip(['topic', 'topic_confidence', 'sample_text'])), fn ($v) => $v !== null);
            }
        }
        if ($row) {
            $row->fill($fill)->forceFill(['hits' => $row->hits + 1, 'last_captured_at' => $now])->save();
        } else {
            HeldLead::create($fill + ['company_id' => $companyId, 'contact_id' => $contactId, 'source' => $key, 'status' => 'held', 'hits' => 1, 'first_captured_at' => $now, 'last_captured_at' => $now]);
        }
        return true;
    }
}
