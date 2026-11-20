<?php

namespace App\Modules\Lead\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\HeldLead;
use App\Services\LeadSourceGate;
use App\Support\Entitlements;
use App\Support\Modules;
use App\Models\{ChatWidget, InstagramAccount, MetaAdAccount, MetaCampaign, WaPhoneNumber};
use App\Modules\WaChat\Models\WahaSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Assignment Rules → Lead sources: switch each incoming lead channel on or off (only the ones the plan includes). */
class LeadSourceController extends Controller
{
    /** GET /lead-assignment-rules/sources */
    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->rows()]);
    }

    /** PUT /lead-assignment-rules/sources { source, enabled } */
    public function update(Request $request): JsonResponse
    {
        $d = $request->validate(['source' => ['required', 'string'], 'enabled' => ['required', 'boolean']]);
        abort_unless(isset(LeadSourceGate::SOURCES[$d['source']]), 422, 'That lead source cannot be switched.');

        $company = auth()->user()->company;
        if (!LeadSourceGate::available($company, $d['source'])) {
            return response()->json(['message' => LeadSourceGate::SOURCES[$d['source']][0] . ' is not included in your plan (or its complimentary period has ended), so there is nothing to switch.', 'error_code' => 'plan_feature_disabled'], 403);
        }

        DB::table('lead_source_settings')->updateOrInsert(
            ['company_id' => $company->id, 'source' => $d['source']],
            ['is_enabled' => $d['enabled'], 'updated_by' => auth()->id(), 'updated_at' => now(), 'created_at' => now()],
        );
        LeadSourceGate::forgetMemo();

        return response()->json(['message' => LeadSourceGate::SOURCES[$d['source']][0] . ($d['enabled']
            ? ' is ON — new leads are created as they arrive.'
            : ' is OFF — new leads wait in Held leads until staff move them.'), 'data' => $this->rows()]);
    }

    /** PUT /lead-assignment-rules/sources/accounts { source, account_type, account_id, enabled } — switch ONE account (session / number / page / widget / ad account) */
    public function updateAccount(Request $request): JsonResponse
    {
        $d = $request->validate(['source' => ['required', 'string'], 'account_id' => ['required', 'integer', 'min:1'], 'enabled' => ['required', 'boolean']]);
        abort_unless(isset(LeadSourceGate::ACCOUNT_TYPES[$d['source']]), 422, 'That source has no separate accounts.');
        $type = LeadSourceGate::ACCOUNT_TYPES[$d['source']];

        $company = auth()->user()->company;
        if (!LeadSourceGate::available($company, $d['source'])) {
            return response()->json(['message' => LeadSourceGate::SOURCES[$d['source']][0] . ' is not included in your plan (or its complimentary period has ended), so its accounts cannot be switched.', 'error_code' => 'plan_feature_disabled'], 403);
        }
        $account = collect($this->accountsFor($company)[$d['source']] ?? [])->firstWhere('id', (int) $d['account_id']);
        abort_unless($account, 404, 'That account was not found.');   // also stops anyone switching another company's account

        DB::table('lead_account_switches')->updateOrInsert(
            ['company_id' => $company->id, 'origin_type' => $type, 'origin_id' => (int) $d['account_id']],
            ['is_enabled' => $d['enabled'], 'updated_by' => auth()->id(), 'updated_at' => now(), 'created_at' => now()],
        );
        LeadSourceGate::forgetMemo();

        return response()->json(['message' => "{$account['label']} is " . ($d['enabled'] ? 'ON — its new leads are created as they arrive.' : 'OFF — its new leads wait in Held leads until staff move them.'), 'data' => $this->rows()]);
    }

    /**
     * GET /lead-assignment-rules/sources/campaigns?account_id=&search=&state=all|off|held&page=&per_page=
     * The campaigns of ONE Meta ad account, searchable and paged — for accounts with hundreds of them.
     */
    public function campaigns(Request $request): JsonResponse
    {
        $d = $request->validate(['account_id' => ['required', 'integer', 'min:1'], 'search' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'in:all,off,held'], 'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:5', 'max:100']]);
        $company = auth()->user()->company;
        abort_unless(MetaAdAccount::where('company_id', $company->id)->whereKey($d['account_id'])->exists(), 404, 'That ad account was not found.');

        $type = LeadSourceGate::CAMPAIGN_TYPES['meta_ads'];
        $q = MetaCampaign::where('company_id', $company->id)->where('meta_ad_account_id', $d['account_id']);
        if (filled($d['search'] ?? null)) {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($d['search'])) . '%';      // a typed % or _ is just a character
            $q->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('meta_campaign_id', 'like', $like));
        }
        $state = $d['state'] ?? 'all';
        if ($state === 'off') {
            $q->whereExists(fn ($e) => $e->selectRaw('1')->from('lead_account_switches as sw')->whereColumn('sw.origin_id', 'meta_campaigns.id')
                ->where('sw.company_id', $company->id)->where('sw.origin_type', $type)->where('sw.is_enabled', 0));
        } elseif ($state === 'held') {
            $q->whereExists(fn ($e) => $e->selectRaw('1')->from('held_leads as h')->whereColumn('h.origin_id', 'meta_campaigns.id')
                ->where('h.company_id', $company->id)->where('h.origin_type', $type)->where('h.status', 'held'));
        }

        $page = $q->orderByDesc('id')->paginate((int) ($d['per_page'] ?? 20));
        $ids = $page->getCollection()->pluck('id');
        $off = DB::table('lead_account_switches')->where('company_id', $company->id)->where('origin_type', $type)->whereIn('origin_id', $ids)->where('is_enabled', 0)->pluck('origin_id')->flip();
        $held = HeldLead::where('company_id', $company->id)->where('status', 'held')->where('origin_type', $type)->whereIn('origin_id', $ids)->selectRaw('origin_id, count(*) c')->groupBy('origin_id')->pluck('c', 'origin_id');

        return response()->json([
            'data' => $page->getCollection()->map(fn ($c) => $this->campaignRow($c, !isset($off[$c->id]), (int) ($held[$c->id] ?? 0)))->values(),
            'total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'has_more' => $page->hasMorePages(),
        ]);
    }

    private function campaignRow($c, bool $enabled, int $held): array
    {
        return ['id' => (int) $c->id, 'label' => $c->name, 'sub' => trim(strtolower((string) $c->status) . ' · ' . strtolower(str_replace('_', ' ', (string) $c->objective)), ' ·'), 'enabled' => $enabled, 'held' => $held];
    }

    /** PUT /lead-assignment-rules/sources/campaigns { campaign_id, enabled } — switch ONE Meta ad campaign by hand (its leads are held while off) */
    public function updateCampaign(Request $request): JsonResponse
    {
        $d = $request->validate(['campaign_id' => ['required', 'integer', 'min:1'], 'enabled' => ['required', 'boolean']]);
        $company = auth()->user()->company;
        if (!LeadSourceGate::available($company, 'meta_ads')) {
            return response()->json(['message' => 'Meta Ads is not included in your plan (or its complimentary period has ended), so its campaigns cannot be switched.', 'error_code' => 'plan_feature_disabled'], 403);
        }
        $campaign = MetaCampaign::where('company_id', $company->id)->find($d['campaign_id']);   // another company's campaign is simply "not found"
        abort_unless($campaign, 404, 'That campaign was not found.');

        DB::table('lead_account_switches')->updateOrInsert(
            ['company_id' => $company->id, 'origin_type' => LeadSourceGate::CAMPAIGN_TYPES['meta_ads'], 'origin_id' => (int) $campaign->id],
            ['is_enabled' => $d['enabled'], 'updated_by' => auth()->id(), 'updated_at' => now(), 'created_at' => now()],
        );
        LeadSourceGate::forgetMemo();

        return response()->json([
            'message' => "Campaign “{$campaign->name}” is " . ($d['enabled'] ? 'ON — its new leads are created as they arrive.' : 'OFF — its new leads wait in Held leads until staff move them.'),
            'campaign' => $this->campaignRow($campaign, (bool) $d['enabled'], HeldLead::where('company_id', $company->id)->where('status', 'held')->where('origin_type', 'ad_campaign')->where('origin_id', $campaign->id)->count()),
            'data' => $this->rows(),
        ]);
    }

    /**
     * The accounts of each switchable source, with their on/off state and how many held leads each has waiting.
     * @return array<string,array<int,array{type:string,id:int,label:string,sub:?string,enabled:bool,held:int}>>
     */
    private function accountsFor(\App\Models\Company $company): array
    {
        $switches = DB::table('lead_account_switches')->where('company_id', $company->id)->get()->mapWithKeys(fn ($r) => ["{$r->origin_type}:{$r->origin_id}" => (bool) $r->is_enabled]);
        $held = HeldLead::where('company_id', $company->id)->where('status', 'held')->whereNotNull('account_id')->selectRaw('account_type, account_id, count(*) c')->groupBy('account_type', 'account_id')->get()
            ->mapWithKeys(fn ($r) => ["{$r->account_type}:{$r->account_id}" => (int) $r->c]);
        // Meta ad accounts can have hundreds of campaigns, so the main list carries only COUNTS; the campaigns themselves
        // are fetched per account, with search + paging, from GET /lead-assignment-rules/sources/campaigns.
        $campaignTotals = MetaCampaign::where('company_id', $company->id)->selectRaw('meta_ad_account_id, count(*) c')->groupBy('meta_ad_account_id')->pluck('c', 'meta_ad_account_id');
        $campaignOff = DB::table('lead_account_switches as sw')->join('meta_campaigns as mc', 'mc.id', '=', 'sw.origin_id')
            ->where('sw.company_id', $company->id)->where('sw.origin_type', 'ad_campaign')->where('sw.is_enabled', 0)->whereNull('mc.deleted_at')
            ->selectRaw('mc.meta_ad_account_id, count(*) c')->groupBy('mc.meta_ad_account_id')->pluck('c', 'meta_ad_account_id');
        $campaignHeld = DB::table('held_leads as h')->join('meta_campaigns as mc', 'mc.id', '=', 'h.origin_id')
            ->where('h.company_id', $company->id)->where('h.status', 'held')->where('h.origin_type', 'ad_campaign')
            ->selectRaw('mc.meta_ad_account_id, count(*) c')->groupBy('mc.meta_ad_account_id')->pluck('c', 'meta_ad_account_id');
        $make = fn (string $type, $id, string $label, ?string $sub) => ['type' => $type, 'id' => (int) $id, 'label' => $label, 'sub' => $sub,
            'enabled' => (bool) ($switches["{$type}:{$id}"] ?? true), 'held' => (int) ($held["{$type}:{$id}"] ?? 0)];

        return [
            'wa_chat'        => WahaSession::where('company_id', $company->id)->orderBy('id')->get()->map(fn ($s) => $make('wa_session', $s->id, $s->display_name ?: $s->session_name, trim(($s->phone ? '+' . $s->phone : '') . ' · ' . $s->status, ' ·') ?: null))->all(),
            'whatsapp_cloud' => WaPhoneNumber::where('company_id', $company->id)->orderBy('id')->get()->map(fn ($n) => $make('phone_number', $n->id, $n->label ?: (string) $n->display_number, $n->display_number))->all(),
            'instagram'      => InstagramAccount::where('company_id', $company->id)->orderBy('id')->get()->map(fn ($a) => $make('instagram_account', $a->id, $a->username ? '@' . ltrim($a->username, '@') : ($a->name ?: "Account #{$a->id}"), $a->name))->all(),
            'website'        => ChatWidget::where('company_id', $company->id)->orderBy('id')->get()->map(fn ($w) => $make('widget', $w->id, $w->name ?: "Widget #{$w->id}", null))->all(),
            'meta_ads'       => MetaAdAccount::where('company_id', $company->id)->orderBy('id')->get()->map(fn ($a) => $make('meta_ads_account', $a->id, $a->ad_account_name ?: ($a->page_name ?: "Ad account #{$a->id}"), $a->page_name) + [
                'campaign_total' => (int) ($campaignTotals[$a->id] ?? 0), 'campaign_off' => (int) ($campaignOff[$a->id] ?? 0), 'campaign_held' => (int) ($campaignHeld[$a->id] ?? 0),
            ])->all(),
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function rows(): array
    {
        $company = auth()->user()->company;
        $accounts = $this->accountsFor($company);
        $ent = Entitlements::forCompany($company);
        $settings = DB::table('lead_source_settings')->where('company_id', $company->id)->pluck('is_enabled', 'source');
        $held = HeldLead::where('company_id', $company->id)->where('status', 'held')->selectRaw('source, count(*) c')->groupBy('source')->pluck('c', 'source');

        $out = [];
        foreach (LeadSourceGate::SOURCES as $key => [$label, $icon, $module]) {
            $available = LeadSourceGate::available($company, $key);
            $until = $module ? ($ent['complimentary'][$module] ?? null) : null;
            $via = !$available ? null : (($module && in_array($module, $ent['from_plan'] ?? [], true)) || !$module || ($ent['all'] ?? false) ? 'plan' : ($until ? 'complimentary' : 'addon'));
            $out[] = [
                'source' => $key, 'label' => $label, 'icon' => $icon,
                'feature' => $module ? (Modules::REGISTRY[$module][0] ?? $module) : 'Leads',
                'available' => $available, 'via' => $via, 'complimentary_until' => $via === 'complimentary' ? $until : null,
                'enabled' => (bool) ($settings[$key] ?? true),
                'held' => (int) ($held[$key] ?? 0),
                // the individual accounts of this source (sessions / numbers / pages / widgets / ad accounts) — each can be switched on its own
                'accounts' => $accounts[$key] ?? [],
            ];
        }
        return $out;
    }
}
