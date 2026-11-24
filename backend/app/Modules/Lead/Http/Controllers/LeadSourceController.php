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
        $d = $request->validate(['source' => ['required', 'string'], 'enabled' => ['required', 'boolean'], 'review_mode' => ['nullable', 'in:off,smart,all']]);
        abort_unless(isset(LeadSourceGate::SOURCES[$d['source']]), 422, 'That lead source cannot be switched.');

        $company = auth()->user()->company;
        if (!LeadSourceGate::available($company, $d['source'])) {
            return response()->json(['message' => LeadSourceGate::SOURCES[$d['source']][0] . ' is not included in your plan (or its complimentary period has ended), so there is nothing to switch.', 'error_code' => 'plan_feature_disabled'], 403);
        }

        DB::table('lead_source_settings')->updateOrInsert(
            ['company_id' => $company->id, 'source' => $d['source']],
            ['is_enabled' => $d['enabled'], 'updated_by' => auth()->id(), 'updated_at' => now(), 'created_at' => now()] + (isset($d['review_mode']) ? ['review_mode' => $d['review_mode']] : []),
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
        $q = $this->campaignQuery($company, (int) $d['account_id'], $d['search'] ?? null, $d['state'] ?? 'all');

        $page = $q->orderByDesc('id')->paginate((int) ($d['per_page'] ?? 20));
        $ids = $page->getCollection()->pluck('id');
        $off = DB::table('lead_account_switches')->where('company_id', $company->id)->where('origin_type', $type)->whereIn('origin_id', $ids)->where('is_enabled', 0)->pluck('origin_id')->flip();
        $held = HeldLead::where('company_id', $company->id)->where('status', 'held')->where('origin_type', $type)->whereIn('origin_id', $ids)->selectRaw('origin_id, count(*) c')->groupBy('origin_id')->pluck('c', 'origin_id');

        return response()->json([
            'data' => $page->getCollection()->map(fn ($c) => $this->campaignRow($c, !isset($off[$c->id]), (int) ($held[$c->id] ?? 0)))->values(),
            'total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'has_more' => $page->hasMorePages(),
        ]);
    }

    /** The campaigns of one ad account, narrowed by the search text and the all / off / held filter — shared by the list and the bulk switch. */
    private function campaignQuery(\App\Models\Company $company, int $accountId, ?string $search, string $state)
    {
        $type = LeadSourceGate::CAMPAIGN_TYPES['meta_ads'];
        $q = MetaCampaign::where('company_id', $company->id)->where('meta_ad_account_id', $accountId);
        if (filled($search)) {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($search)) . '%';      // a typed % or _ is just a character
            $q->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('meta_campaign_id', 'like', $like));
        }
        if ($state === 'off') {
            $q->whereExists(fn ($e) => $e->selectRaw('1')->from('lead_account_switches as sw')->whereColumn('sw.origin_id', 'meta_campaigns.id')
                ->where('sw.company_id', $company->id)->where('sw.origin_type', $type)->where('sw.is_enabled', 0));
        } elseif ($state === 'held') {
            $q->whereExists(fn ($e) => $e->selectRaw('1')->from('held_leads as h')->whereColumn('h.origin_id', 'meta_campaigns.id')
                ->where('h.company_id', $company->id)->where('h.origin_type', $type)->where('h.status', 'held'));
        }
        return $q;
    }

    /** Most campaigns one bulk switch may change at once — a deliberate, reviewable batch, not "everything the company has ever synced". */
    public const BULK_CAP = 2000;

    /**
     * PUT /lead-assignment-rules/sources/campaigns/bulk
     *   { account_id, enabled, ids: [campaign ids] }                        — the ticked ones
     *   { account_id, enabled, all_matching: true, search?, state? }        — everything the current search / filter shows
     * Idempotent: only campaigns whose state really changes are written, and the answer says how many that was.
     */
    public function bulkCampaigns(Request $request): JsonResponse
    {
        $d = $request->validate(['account_id' => ['required', 'integer', 'min:1'], 'enabled' => ['required', 'boolean'],
            'ids' => ['nullable', 'array', 'max:500'], 'ids.*' => ['integer', 'min:1'], 'all_matching' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:100'], 'state' => ['nullable', 'in:all,off,held']]);
        abort_if(empty($d['ids']) && empty($d['all_matching']), 422, 'Pick at least one campaign.');

        $company = auth()->user()->company;
        if (!LeadSourceGate::available($company, 'meta_ads')) {
            return response()->json(['message' => 'Meta Ads is not included in your plan (or its complimentary period has ended), so its campaigns cannot be switched.', 'error_code' => 'plan_feature_disabled'], 403);
        }
        abort_unless(MetaAdAccount::where('company_id', $company->id)->whereKey($d['account_id'])->exists(), 404, 'That ad account was not found.');

        $q = $this->campaignQuery($company, (int) $d['account_id'], $d['search'] ?? null, $d['state'] ?? 'all');
        if (empty($d['all_matching'])) { $q->whereIn('id', $d['ids']); }      // ids from any other account or company simply do not match
        if ($q->count() > self::BULK_CAP) {
            return response()->json(['message' => 'That is more than ' . self::BULK_CAP . ' campaigns at once. Narrow the search or the filter first.', 'error_code' => 'bulk_too_large'], 422);
        }
        $ids = $q->pluck('id');

        $type = LeadSourceGate::CAMPAIGN_TYPES['meta_ads'];
        $target = (bool) $d['enabled'];
        $alreadyOff = DB::table('lead_account_switches')->where('company_id', $company->id)->where('origin_type', $type)->whereIn('origin_id', $ids)->where('is_enabled', 0)->pluck('origin_id')->flip();
        $change = $ids->filter(fn ($id) => $target ? isset($alreadyOff[$id]) : !isset($alreadyOff[$id]))->values();      // only what really changes

        DB::transaction(function () use ($change, $company, $type, $target) {
            foreach ($change->chunk(500) as $chunk) {
                DB::table('lead_account_switches')->upsert(
                    $chunk->map(fn ($id) => ['company_id' => $company->id, 'origin_type' => $type, 'origin_id' => (int) $id, 'is_enabled' => $target, 'updated_by' => auth()->id(), 'created_at' => now(), 'updated_at' => now()])->all(),
                    ['company_id', 'origin_type', 'origin_id'], ['is_enabled', 'updated_by', 'updated_at'],
                );
            }
        });
        LeadSourceGate::forgetMemo();

        $n = $change->count();
        return response()->json([
            'changed' => $n, 'matched' => $ids->count(),
            'message' => $n === 0 ? 'Nothing to change — those campaigns were already ' . ($target ? 'on.' : 'off.')
                : "{$n} campaign" . ($n === 1 ? ' is' : 's are') . ' now ' . ($target ? 'ON — new leads are created as they arrive.' : 'OFF — new leads wait in Held leads until staff move them.'),
            'data' => $this->rows(),
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
        $modes = DB::table('lead_source_settings')->where('company_id', $company->id)->pluck('review_mode', 'source');
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
                'review_mode' => $modes[$key] ?? 'off',
                'held' => (int) ($held[$key] ?? 0),
                // the individual accounts of this source (sessions / numbers / pages / widgets / ad accounts) — each can be switched on its own
                'accounts' => $accounts[$key] ?? [],
            ];
        }
        return $out;
    }

    /** GET /lead-assignment-rules/sheet — the "also save new leads to a Google Sheet" switch, its link, and whether it can be turned on */
    public function sheet(): JsonResponse
    {
        return response()->json(['data' => $this->sheetState()]);
    }

    /** PUT /lead-assignment-rules/sheet { enabled } */
    public function updateSheet(Request $request): JsonResponse
    {
        $enabled = (bool) $request->validate(['enabled' => ['required', 'boolean']])['enabled'];
        $company = auth()->user()->company;
        $sheets  = app(\App\Services\LeadSheet::class);

        if ($enabled) {
            $opt = $sheets->options($company);
            if (!$opt['available']) {
                return response()->json(['message' => $opt['reason'], 'errors' => ['enabled' => [$opt['reason']]]], 422);
            }
            $integration = \App\Models\GoogleIntegration::where('company_id', $company->id)->where('is_active', true)->first();
            try {
                $sheets->ensureSheet($company->id, $integration);   // created now, so the link can be shown straight away
            } catch (\Throwable $e) {
                return response()->json(['message' => 'Could not create the Google Sheet: ' . $e->getMessage()], 422);
            }
        }
        DB::table('lead_sheet_settings')->updateOrInsert(['company_id' => $company->id], ['is_enabled' => $enabled, 'updated_by' => auth()->id(), 'updated_at' => now(), 'created_at' => now()]);

        return response()->json([
            'message' => $enabled ? 'ON — every new lead is also added to the sheet as it arrives (leads that came before are not added).' : 'OFF — new leads are saved in Teamzo only.',
            'data'    => $this->sheetState(),
        ]);
    }

    private function sheetState(): array
    {
        $company = auth()->user()->company;
        $sheets  = app(\App\Services\LeadSheet::class);
        $s = $sheets->settings($company->id);
        return ['enabled' => (bool) $s->is_enabled, 'url' => $s->sheet_url] + $sheets->options($company);
    }
}
