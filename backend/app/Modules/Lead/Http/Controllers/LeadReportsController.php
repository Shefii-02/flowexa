<?php

namespace App\Modules\Lead\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Analytics\Http\Controllers\TeamMembersController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The lead report pack (GET /leads/reports/…): scheduled follow-ups calendar, staff performance, rejected leads, detailed summary and
 * campaign-wise (Meta lead ads). A team head / admin sees the whole company; everyone else sees their own leads and numbers.
 */
class LeadReportsController extends Controller
{
    private function cid(): int { return (int) auth()->user()->company_id; }

    private function team(): bool { return TeamMembersController::canSeeTeam(auth()->user()->loadMissing('role')); }

    /** [from, to] as Carbon day bounds; defaults to this month. */
    private function range(Request $r): array
    {
        $from = $r->filled('from') ? Carbon::parse($r->query('from'))->startOfDay() : now()->startOfMonth();
        $to = $r->filled('to') ? Carbon::parse($r->query('to'))->endOfDay() : now()->endOfMonth();

        return [$from, $to];
    }

    private function leads()
    {
        $u = auth()->user();

        return DB::table('leads')->where('leads.company_id', $this->cid())->whereNull('leads.deleted_at')
            ->when(! $this->team(), fn ($q) => $q->whereIn('leads.assigned_to', $u->leadOwnerIds()));
    }

    // GET /leads/reports/scheduled?from&to&staff_id&category&source[&date=YYYY-MM-DD]
    public function scheduled(Request $r): JsonResponse
    {
        [$from, $to] = $this->range($r);
        $u = auth()->user();
        $q = DB::table('crm_tasks as t')->where('t.company_id', $this->cid())->where('t.type', 'followup')->where('t.status', 'open')
            ->whereBetween('t.due_at', [$from->toDateTimeString(), $to->toDateTimeString()])
            ->when(! $this->team(), fn ($w) => $w->whereIn('t.assigned_to', $u->leadOwnerIds()))
            ->when($r->filled('staff_id'), fn ($w) => $w->where('t.assigned_to', $r->integer('staff_id')));
        if ($r->filled('category') || $r->filled('source')) {
            $q->join('leads as l', fn ($j) => $j->on('l.contact_id', '=', 't.contact_id')->whereNull('l.deleted_at'))
                ->when($r->filled('category'), fn ($w) => $w->where('l.category', $r->query('category')))
                ->when($r->filled('source'), fn ($w) => $w->where('l.source', $r->query('source')));
        }

        if ($r->filled('date')) {   // the follow-ups of one day (Day / Week drill-down)
            $rows = (clone $q)->whereDate('t.due_at', $r->query('date'))->leftJoin('contacts as c', 'c.id', '=', 't.contact_id')->leftJoin('users as u', 'u.id', '=', 't.assigned_to')
                ->orderBy('t.due_at')->limit(300)->get(['t.id', 't.title', 't.due_at', 'c.name as contact', 'c.phone', 'u.name as staff']);

            return response()->json(['date' => $r->query('date'), 'items' => $rows]);
        }

        $days = $q->selectRaw('DATE(t.due_at) d, COUNT(DISTINCT t.id) n')->groupBy('d')->orderBy('d')->get();

        return response()->json(['from' => $from->toDateString(), 'to' => $to->toDateString(), 'days' => $days->map(fn ($d) => ['date' => $d->d, 'count' => (int) $d->n])->all(), 'total' => (int) $days->sum('n')]);
    }

    // GET /leads/reports/staff?from&to
    public function staff(Request $r): JsonResponse
    {
        [$from, $to] = $this->range($r);
        $u = auth()->user();
        $f = $from->toDateTimeString(); $t = $to->toDateTimeString();
        $people = DB::table('users')->where('company_id', $this->cid())->where('is_active', true)
            ->when(! $this->team(), fn ($q) => $q->whereIn('id', $u->leadOwnerIds()))->orderBy('name')->get(['id', 'name', 'designation']);

        $leads = DB::table('leads')->where('company_id', $this->cid())->whereNull('deleted_at')->whereNotNull('assigned_to');
        $assigned = (clone $leads)->selectRaw('assigned_to u, COUNT(*) n')->groupBy('u')->pluck('n', 'u');
        $created = (clone $leads)->whereBetween('created_at', [$f, $t])->selectRaw('assigned_to u, COUNT(*) n')->groupBy('u')->pluck('n', 'u');
        $closed = (clone $leads)->whereBetween('enrolled_at', [$f, $t])->selectRaw('assigned_to u, COUNT(*) n')->groupBy('u')->pluck('n', 'u');
        $rejected = (clone $leads)->whereBetween('lost_at', [$f, $t])->selectRaw('assigned_to u, COUNT(*) n')->groupBy('u')->pluck('n', 'u');
        $acts = DB::table('lead_activities')->where('company_id', $this->cid())->where('kind', 'call')->whereBetween('happened_at', [$f, $t])
            ->selectRaw("user_id u, COUNT(*) calls, SUM(outcome = 'connected') connected, COALESCE(SUM(duration_seconds),0) secs")->groupBy('u')->get()->keyBy('u');
        $follow = DB::table('crm_tasks')->where('company_id', $this->cid())->where('type', 'followup')->where('status', 'done')->whereBetween('completed_at', [$f, $t])
            ->selectRaw('assigned_to u, COUNT(*) n')->groupBy('u')->pluck('n', 'u');

        $rows = $people->map(function ($p) use ($assigned, $created, $closed, $rejected, $acts, $follow) {
            $a = $acts[$p->id] ?? null;
            $as = (int) ($assigned[$p->id] ?? 0);

            return ['id' => $p->id, 'name' => $p->name, 'designation' => $p->designation, 'assigned' => $as, 'new' => (int) ($created[$p->id] ?? 0),
                'calls' => (int) ($a->calls ?? 0), 'connected' => (int) ($a->connected ?? 0), 'call_seconds' => (int) ($a->secs ?? 0), 'followups_done' => (int) ($follow[$p->id] ?? 0),
                'closed' => (int) ($closed[$p->id] ?? 0), 'rejected' => (int) ($rejected[$p->id] ?? 0), 'conversion' => $as > 0 ? round(($closed[$p->id] ?? 0) / $as * 100, 1) : 0];
        })->values();

        return response()->json(['from' => $from->toDateString(), 'to' => $to->toDateString(), 'rows' => $rows]);
    }

    // GET /leads/reports/rejected?from&to&staff_id&category
    public function rejected(Request $r): JsonResponse
    {
        [$from, $to] = $this->range($r);
        $rows = $this->leads()->leftJoin('contacts as c', 'c.id', '=', 'leads.contact_id')->leftJoin('users as u', 'u.id', '=', 'leads.assigned_to')
            ->where('leads.stage', 'lost')->whereBetween('leads.lost_at', [$from->toDateTimeString(), $to->toDateTimeString()])
            ->when($r->filled('staff_id'), fn ($q) => $q->where('leads.assigned_to', $r->integer('staff_id')))
            ->when($r->filled('category'), fn ($q) => $q->where('leads.category', $r->query('category')))
            ->orderByDesc('leads.lost_at')->limit(500)
            ->get(['leads.id', 'c.name', 'c.phone', 'leads.category', 'leads.source', 'leads.close_reason as reason', 'leads.lost_at', 'leads.created_at', 'u.name as staff']);

        return response()->json(['from' => $from->toDateString(), 'to' => $to->toDateString(), 'total' => $rows->count(), 'rows' => $rows]);
    }

    // GET /leads/reports/summary?from&to
    public function summary(Request $r): JsonResponse
    {
        [$from, $to] = $this->range($r);
        $f = $from->toDateTimeString(); $t = $to->toDateTimeString();
        $in = fn () => $this->leads()->whereBetween('leads.created_at', [$f, $t]);
        $group = fn (string $col) => $in()->selectRaw("COALESCE(NULLIF($col, ''), 'Uncategorised') k, COUNT(*) n")->groupBy('k')->orderByDesc('n')->limit(12)->get()->map(fn ($x) => ['label' => (string) $x->k, 'count' => (int) $x->n])->all();
        $total = $in()->count();
        $won = $this->leads()->whereBetween('leads.enrolled_at', [$f, $t])->count();
        $lost = $this->leads()->whereBetween('leads.lost_at', [$f, $t])->count();

        return response()->json([
            'from' => $from->toDateString(), 'to' => $to->toDateString(),
            'totals' => ['created' => $total, 'closed' => $won, 'rejected' => $lost, 'active' => $this->leads()->whereNotIn('leads.stage', ['enrolled', 'lost'])->count(), 'conversion' => $total > 0 ? round($won / $total * 100, 1) : 0],
            'by_stage' => $group('leads.stage'), 'by_priority' => $group('leads.priority'), 'by_source' => $group('leads.source'), 'by_category' => $group('leads.category'),
            'daily' => $in()->selectRaw('DATE(leads.created_at) d, COUNT(*) n')->groupBy('d')->orderBy('d')->get()->map(fn ($x) => ['date' => $x->d, 'count' => (int) $x->n])->all(),
        ]);
    }

    // GET /leads/reports/campaigns?from&to — Meta lead ads, campaign-wise
    public function campaigns(Request $r): JsonResponse
    {
        [$from, $to] = $this->range($r);
        $cid = $this->cid();
        $rows = DB::table('meta_leads as m')->leftJoin('leads as l', fn ($j) => $j->on('l.id', '=', 'm.lead_id')->whereNull('l.deleted_at'))
            ->leftJoin('meta_campaigns as c', 'c.id', '=', 'm.meta_campaign_id')->leftJoin('meta_lead_forms as f', 'f.id', '=', 'm.meta_lead_form_id')
            ->where('m.company_id', $cid)->whereBetween('m.created_at', [$from->toDateTimeString(), $to->toDateTimeString()])
            ->when(! $this->team(), fn ($q) => $q->whereIn('l.assigned_to', auth()->user()->leadOwnerIds()))
            ->selectRaw("COALESCE(c.name, f.name, 'Unknown campaign') name, COUNT(*) total, SUM(l.stage = 'lost') rejected,
                         SUM(l.stage IN ('new','contacted','follow_up')) followup, SUM(l.stage = 'enrolled') confirmed")
            ->groupBy('name')->orderByDesc('total')->get()
            ->map(fn ($x) => ['name' => $x->name, 'total' => (int) $x->total, 'rejected' => (int) $x->rejected, 'in_followup' => (int) $x->followup, 'confirmed' => (int) $x->confirmed,
                'conversion' => $x->total > 0 ? round($x->confirmed / $x->total * 100, 1) : 0]);

        return response()->json(['from' => $from->toDateString(), 'to' => $to->toDateString(), 'rows' => $rows->values(),
            'totals' => ['total' => $rows->sum('total'), 'rejected' => $rows->sum('rejected'), 'in_followup' => $rows->sum('in_followup'), 'confirmed' => $rows->sum('confirmed'),
                'conversion' => $rows->sum('total') > 0 ? round($rows->sum('confirmed') / $rows->sum('total') * 100, 1) : 0]]);
    }

    // GET /leads/reports/sources — one row per lead source: how many leads came in, where they stand, and how many wait for a check
    public function sources(Request $r): JsonResponse
    {
        [$from, $to] = $this->range($r);
        $rows = $this->leads()->whereBetween('leads.created_at', [$from->toDateTimeString(), $to->toDateTimeString()])
            ->selectRaw("COALESCE(leads.source, 'manual') as source, count(*) as total,
                SUM(leads.stage = 'new') as new_leads, SUM(leads.stage = 'follow_up') as in_followup,
                SUM(leads.stage = 'enrolled') as converted, SUM(leads.stage = 'lost') as lost,
                SUM(leads.verified_at IS NOT NULL) as verified, SUM(leads.assigned_to IS NULL) as unassigned")
            ->groupBy(DB::raw("COALESCE(leads.source, 'manual')"))->get()->keyBy('source');

        $waiting = $this->team()
            ? DB::table('held_leads')->where('company_id', $this->cid())->where('status', 'held')
                ->selectRaw("source, SUM(held_reason = 'needs_review') as review, SUM(held_reason <> 'needs_review' OR held_reason IS NULL) as switched_off")->groupBy('source')->get()->keyBy('source')
            : collect();

        $keys = $rows->keys()->merge($waiting->keys())->unique()->values();
        $out = $keys->map(function ($k) use ($rows, $waiting) {
            $x = $rows[$k] ?? null; $w = $waiting[$k] ?? null;
            $total = (int) ($x->total ?? 0); $conv = (int) ($x->converted ?? 0);
            return [
                'source' => $k, 'label' => config("lead_sources.{$k}.label") ?? ucwords(str_replace('_', ' ', (string) $k)), 'icon' => config("lead_sources.{$k}.icon"),
                'total' => $total, 'new' => (int) ($x->new_leads ?? 0), 'in_followup' => (int) ($x->in_followup ?? 0), 'converted' => $conv, 'lost' => (int) ($x->lost ?? 0),
                'verified' => (int) ($x->verified ?? 0), 'unassigned' => (int) ($x->unassigned ?? 0),
                'conversion' => $total ? round($conv / $total * 100, 1) : 0,
                'awaiting_review' => (int) ($w->review ?? 0), 'switched_off' => (int) ($w->switched_off ?? 0),
            ];
        })->sortByDesc('total')->values();

        return response()->json(['rows' => $out, 'totals' => ['total' => $out->sum('total'), 'converted' => $out->sum('converted'), 'awaiting_review' => $out->sum('awaiting_review'), 'verified' => $out->sum('verified')]]);
    }

    private const STAGES = ['new', 'contacted', 'follow_up', 'enrolled', 'lost'];

    // GET /leads/reports/funnel?from&to — how many leads of the period reached each stage, drop-off, and average time spent in each stage
    public function funnel(Request $r): JsonResponse
    {
        [$from, $to] = $this->range($r);
        $f = $from->toDateTimeString(); $t = $to->toDateTimeString();
        $leads = $this->leads()->whereBetween('leads.created_at', [$f, $t])->get(['leads.id', 'leads.stage', 'leads.created_at']);
        $ids = $leads->pluck('id');
        $byLead = [];
        foreach ($ids->chunk(2000) as $chunk) {
            DB::table('lead_events')->where('company_id', $this->cid())->where('event', 'stage_changed')->whereIn('lead_id', $chunk)->orderBy('created_at')
                ->get(['lead_id', 'payload', 'created_at'])->each(function ($e) use (&$byLead) { $byLead[$e->lead_id][] = $e; });
        }

        $reached = array_fill_keys(self::STAGES, 0);
        $secs = array_fill_keys(self::STAGES, 0);
        $samples = array_fill_keys(self::STAGES, 0);
        foreach ($leads as $l) {
            $seen = ['new' => true];
            $cursor = Carbon::parse($l->created_at);
            $cur = 'new';
            foreach ($byLead[$l->id] ?? [] as $e) {
                $p = is_string($e->payload) ? json_decode($e->payload, true) : (array) $e->payload;
                $at = Carbon::parse($e->created_at);
                $from_ = $p['from'] ?? $cur; $to_ = $p['to'] ?? null;
                if (isset($secs[$from_])) { $secs[$from_] += max(0, $cursor->diffInSeconds($at)); $samples[$from_]++; }
                if ($to_) { $seen[$to_] = true; $cur = $to_; }
                $cursor = $at;
            }
            $seen[$l->stage] = true;
            foreach ($seen as $st => $_) { if (isset($reached[$st])) $reached[$st]++; }
        }

        $total = $leads->count();
        $prev = null;
        $rows = collect(self::STAGES)->map(function ($st) use ($reached, $secs, $samples, $total, &$prev) {
            $n = $reached[$st];
            $row = ['stage' => $st, 'reached' => $n, 'pct_of_total' => $total ? round($n / $total * 100, 1) : 0,
                'avg_hours_in_stage' => $samples[$st] ? round($secs[$st] / $samples[$st] / 3600, 1) : null,
                'dropped_from_previous' => in_array($st, ['new', 'contacted', 'follow_up'], true) && $prev !== null ? max(0, $prev - $n) : null];
            if (in_array($st, ['new', 'contacted', 'follow_up'], true)) $prev = $n;

            return $row;
        })->values();

        return response()->json(['from' => $from->toDateString(), 'to' => $to->toDateString(), 'total' => $total, 'rows' => $rows,
            'note' => 'Time in stage is measured from recorded stage changes; leads that never left a stage are not counted in its average.']);
    }

    // GET /leads/reports/lost-reasons?from&to — rejected leads grouped by reason, plus split by source, staff and category
    public function lostReasons(Request $r): JsonResponse
    {
        [$from, $to] = $this->range($r);
        $base = fn () => $this->leads()->where('leads.stage', 'lost')->whereBetween('leads.lost_at', [$from->toDateTimeString(), $to->toDateTimeString()]);
        $group = fn (string $expr, ?string $join = null) => $base()
            ->when($join === 'users', fn ($q) => $q->leftJoin('users as u', 'u.id', '=', 'leads.assigned_to'))
            ->selectRaw("COALESCE(NULLIF($expr, ''), 'Not specified') k, COUNT(*) n")->groupBy('k')->orderByDesc('n')->limit(15)->get()
            ->map(fn ($x) => ['label' => (string) $x->k, 'count' => (int) $x->n])->all();
        $total = $base()->count();

        return response()->json(['from' => $from->toDateString(), 'to' => $to->toDateString(), 'total' => $total,
            'by_reason' => $group('TRIM(leads.close_reason)'), 'by_source' => $group('leads.source'), 'by_staff' => $group('u.name', 'users'), 'by_category' => $group('leads.category'),
            'avg_days_to_lose' => round((float) ($base()->selectRaw('AVG(TIMESTAMPDIFF(HOUR, leads.created_at, leads.lost_at)) h')->value('h') ?? 0) / 24, 1)]);
    }

    // GET /leads/reports/discipline?from&to&stale_days=7 — speed to first contact, overdue follow-ups and stale leads per staff member
    public function discipline(Request $r): JsonResponse
    {
        [$from, $to] = $this->range($r);
        $f = $from->toDateTimeString(); $t = $to->toDateTimeString();
        $stale = max(1, min(90, (int) $r->query('stale_days', 7)));
        $cid = $this->cid(); $u = auth()->user();
        $people = DB::table('users')->where('company_id', $cid)->where('is_active', true)
            ->when(! $this->team(), fn ($q) => $q->whereIn('id', $u->leadOwnerIds()))->orderBy('name')->get(['id', 'name', 'designation']);

        // minutes from lead creation to the first logged activity, for leads created in the period
        $first = DB::table('leads')->join('lead_activities as a', fn ($j) => $j->on('a.lead_id', '=', 'leads.id')->where('a.is_first_contact', true))
            ->where('leads.company_id', $cid)->whereNull('leads.deleted_at')->whereBetween('leads.created_at', [$f, $t])->whereNotNull('leads.assigned_to')
            ->selectRaw('leads.assigned_to u, COUNT(*) n, AVG(GREATEST(0, TIMESTAMPDIFF(MINUTE, leads.created_at, a.happened_at))) mins')->groupBy('u')->get()->keyBy('u');
        $newLeads = DB::table('leads')->where('company_id', $cid)->whereNull('deleted_at')->whereBetween('created_at', [$f, $t])->whereNotNull('assigned_to')
            ->selectRaw('assigned_to u, COUNT(*) n')->groupBy('u')->pluck('n', 'u');
        $overdue = DB::table('crm_tasks')->where('company_id', $cid)->where('type', 'followup')->where('status', 'open')->where('due_at', '<', now())
            ->selectRaw('assigned_to u, COUNT(*) n, MIN(due_at) oldest')->groupBy('u')->get()->keyBy('u');
        $missed = DB::table('crm_tasks')->where('company_id', $cid)->where('type', 'followup')->whereBetween('due_at', [$f, $t])
            ->selectRaw("assigned_to u, COUNT(*) due, SUM(status = 'done') done")->groupBy('u')->get()->keyBy('u');
        $staleRows = DB::table('leads')->where('leads.company_id', $cid)->whereNull('leads.deleted_at')->whereNotIn('leads.stage', ['enrolled', 'lost'])->whereNotNull('leads.assigned_to')
            ->whereRaw('COALESCE((SELECT MAX(a.happened_at) FROM lead_activities a WHERE a.lead_id = leads.id), leads.created_at) < ?', [now()->subDays($stale)])
            ->selectRaw('leads.assigned_to u, COUNT(*) n')->groupBy('u')->pluck('n', 'u');

        $rows = $people->map(function ($p) use ($first, $newLeads, $overdue, $missed, $staleRows) {
            $m = $missed[$p->id] ?? null; $due = (int) ($m->due ?? 0); $done = (int) ($m->done ?? 0);

            return ['id' => $p->id, 'name' => $p->name, 'designation' => $p->designation,
                'new_leads' => (int) ($newLeads[$p->id] ?? 0), 'contacted' => (int) ($first[$p->id]->n ?? 0),
                'avg_first_contact_minutes' => isset($first[$p->id]) ? (int) round($first[$p->id]->mins) : null,
                'overdue_followups' => (int) ($overdue[$p->id]->n ?? 0), 'oldest_overdue' => $overdue[$p->id]->oldest ?? null,
                'followups_due' => $due, 'followups_done' => $done, 'followup_rate' => $due ? round($done / $due * 100, 1) : null,
                'stale_leads' => (int) ($staleRows[$p->id] ?? 0)];
        })->values();

        return response()->json(['from' => $from->toDateString(), 'to' => $to->toDateString(), 'stale_days' => $stale, 'rows' => $rows,
            'totals' => ['overdue_followups' => $rows->sum('overdue_followups'), 'stale_leads' => $rows->sum('stale_leads'), 'new_leads' => $rows->sum('new_leads'), 'contacted' => $rows->sum('contacted')]]);
    }

    // GET /leads/reports/source-revenue?from&to — per source: leads → enrolled → money collected; plus Meta campaigns with ad spend, cost per lead and per enrolment
    public function sourceRevenue(Request $r): JsonResponse
    {
        [$from, $to] = $this->range($r);
        $f = $from->toDateTimeString(); $t = $to->toDateTimeString(); $cid = $this->cid();
        $collected = fn () => DB::table('lead_installment_payments as p')->join('leads', 'leads.id', '=', 'p.lead_id')->where('p.company_id', $cid)->whereNull('p.reversed_at')
            ->when(! $this->team(), fn ($q) => $q->whereIn('leads.assigned_to', auth()->user()->leadOwnerIds()));

        $leadRows = $this->leads()->whereBetween('leads.created_at', [$f, $t])
            ->selectRaw("COALESCE(NULLIF(leads.source, ''), 'manual') source, COUNT(*) total, SUM(leads.stage = 'enrolled') enrolled, SUM(leads.stage = 'lost') lost")
            ->groupBy('source')->get()->keyBy('source');
        $money = $collected()->whereBetween('leads.created_at', [$f, $t])
            ->selectRaw("COALESCE(NULLIF(leads.source, ''), 'manual') source, SUM(p.amount + p.late_fee_amount) amt")->groupBy('source')->pluck('amt', 'source');
        $moneyInPeriod = (float) $collected()->whereBetween('p.paid_on', [$from->toDateString(), $to->toDateString()])->sum(DB::raw('p.amount + p.late_fee_amount'));

        $rows = $leadRows->map(function ($x, $k) use ($money) {
            $rev = (float) ($money[$k] ?? 0);

            return ['source' => $k, 'label' => config("lead_sources.{$k}.label") ?? ucwords(str_replace('_', ' ', (string) $k)), 'total' => (int) $x->total, 'enrolled' => (int) $x->enrolled, 'lost' => (int) $x->lost,
                'conversion' => $x->total ? round($x->enrolled / $x->total * 100, 1) : 0, 'revenue' => round($rev, 2),
                'revenue_per_lead' => $x->total ? round($rev / $x->total, 2) : 0];
        })->sortByDesc('revenue')->values();

        $spend = DB::table('meta_insights')->where('company_id', $cid)->where('object_type', 'campaign')->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('object_id, SUM(spend) spend, SUM(impressions) impressions, SUM(clicks) clicks')->groupBy('object_id')->get()->keyBy('object_id');
        $camp = DB::table('meta_leads as m')->join('meta_campaigns as c', 'c.id', '=', 'm.meta_campaign_id')->leftJoin('leads as l', fn ($j) => $j->on('l.id', '=', 'm.lead_id')->whereNull('l.deleted_at'))
            ->where('m.company_id', $cid)->whereBetween('m.created_at', [$f, $t])
            ->when(! $this->team(), fn ($q) => $q->whereIn('l.assigned_to', auth()->user()->leadOwnerIds()))
            ->selectRaw("c.id, c.name, COUNT(*) leads, SUM(l.stage = 'enrolled') enrolled, COALESCE(SUM(CASE WHEN l.stage = 'enrolled' THEN l.sale_value ELSE 0 END), 0) sale_value")->groupBy('c.id', 'c.name')->get();
        $leadMoney = $collected()->join('meta_leads as m2', 'm2.lead_id', '=', 'leads.id')->whereBetween('leads.created_at', [$f, $t])
            ->selectRaw('m2.meta_campaign_id c, SUM(p.amount + p.late_fee_amount) amt')->groupBy('c')->pluck('amt', 'c');
        $campaigns = $camp->map(function ($c) use ($spend, $leadMoney) {
            $sp = (float) ($spend[$c->id]->spend ?? 0); $rev = (float) ($leadMoney[$c->id] ?? 0);
            $value = max($rev, (float) $c->sale_value);   // what the leads were worth: deal value of enrolled leads, or money actually collected if higher

            return ['id' => $c->id, 'name' => $c->name, 'leads' => (int) $c->leads, 'enrolled' => (int) $c->enrolled, 'spend' => round($sp, 2), 'revenue' => round($rev, 2),
                'value' => round($value, 2), 'net' => round($value - $sp, 2),
                'cost_per_lead' => $c->leads ? round($sp / $c->leads, 2) : null, 'value_per_lead' => $c->leads ? round($value / $c->leads, 2) : null,
                'cost_per_enrolment' => $c->enrolled ? round($sp / $c->enrolled, 2) : null,
                'roas' => $sp > 0 ? round($value / $sp, 2) : null];
        })->sortByDesc('spend')->values();

        return response()->json(['from' => $from->toDateString(), 'to' => $to->toDateString(), 'rows' => $rows, 'campaigns' => $campaigns,
            'totals' => ['leads' => $rows->sum('total'), 'enrolled' => $rows->sum('enrolled'), 'revenue' => round($rows->sum('revenue'), 2), 'collected_in_period' => round($moneyInPeriod, 2),
                'ad_spend' => round($campaigns->sum('spend'), 2), 'ad_value' => round($campaigns->sum('value'), 2), 'ad_net' => round($campaigns->sum('net'), 2), 'ad_leads' => $campaigns->sum('leads'), 'ad_enrolled' => $campaigns->sum('enrolled')],
            'note' => 'Revenue = installment payments (not reversed) from leads created in the period. Campaign value = deal value of enrolled leads or money collected, whichever is higher. Ad spend comes from synced Meta insights.']);
    }

    // GET /leads/reports/trends?from&to — this period against the previous period of equal length, plus a staff leaderboard with change
    public function trends(Request $r): JsonResponse
    {
        [$from, $to] = $this->range($r);
        $days = max(1, $from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay()) + 1);
        $pFrom = $from->copy()->subDays($days)->startOfDay(); $pTo = $from->copy()->subDay()->endOfDay();
        $cid = $this->cid();

        $metrics = function (Carbon $a, Carbon $b) use ($cid) {
            $x = $a->toDateTimeString(); $y = $b->toDateTimeString();
            $acts = DB::table('lead_activities')->where('company_id', $cid)->where('kind', 'call')->whereBetween('happened_at', [$x, $y])
                ->when(! $this->team(), fn ($q) => $q->whereIn('user_id', auth()->user()->leadOwnerIds()));
            $rev = DB::table('lead_installment_payments as p')->join('leads', 'leads.id', '=', 'p.lead_id')->where('p.company_id', $cid)->whereNull('p.reversed_at')
                ->whereBetween('p.paid_on', [$a->toDateString(), $b->toDateString()])
                ->when(! $this->team(), fn ($q) => $q->whereIn('leads.assigned_to', auth()->user()->leadOwnerIds()));
            $created = $this->leads()->whereBetween('leads.created_at', [$x, $y])->count();
            $closed = $this->leads()->whereBetween('leads.enrolled_at', [$x, $y])->count();

            return ['leads_created' => $created, 'closed' => $closed, 'rejected' => $this->leads()->whereBetween('leads.lost_at', [$x, $y])->count(),
                'calls' => (clone $acts)->count(), 'connected_calls' => (clone $acts)->where('outcome', 'connected')->count(),
                'followups_done' => DB::table('crm_tasks')->where('company_id', $cid)->where('type', 'followup')->where('status', 'done')->whereBetween('completed_at', [$x, $y])
                    ->when(! $this->team(), fn ($q) => $q->whereIn('assigned_to', auth()->user()->leadOwnerIds()))->count(),
                'conversion' => $created ? round($closed / $created * 100, 1) : 0, 'revenue' => round((float) $rev->sum(DB::raw('p.amount + p.late_fee_amount')), 2)];
        };
        $cur = $metrics($from, $to); $prev = $metrics($pFrom, $pTo);
        $cmp = collect($cur)->map(fn ($v, $k) => ['key' => $k, 'current' => $v, 'previous' => $prev[$k], 'change_pct' => $prev[$k] > 0 ? round(($v - $prev[$k]) / $prev[$k] * 100, 1) : null])->values();

        $board = function (Carbon $a, Carbon $b) use ($cid) {
            return DB::table('leads')->where('company_id', $cid)->whereNull('deleted_at')->whereNotNull('assigned_to')->whereBetween('enrolled_at', [$a->toDateTimeString(), $b->toDateTimeString()])
                ->selectRaw('assigned_to u, COUNT(*) n')->groupBy('u')->pluck('n', 'u');
        };
        $curB = $board($from, $to); $prevB = $board($pFrom, $pTo);
        $names = DB::table('users')->where('company_id', $cid)->when(! $this->team(), fn ($q) => $q->whereIn('id', auth()->user()->leadOwnerIds()))->pluck('name', 'id');
        $leaders = $names->map(fn ($n, $id) => ['id' => $id, 'name' => $n, 'closed' => (int) ($curB[$id] ?? 0), 'previous' => (int) ($prevB[$id] ?? 0), 'change' => (int) ($curB[$id] ?? 0) - (int) ($prevB[$id] ?? 0)])
            ->filter(fn ($x) => $x['closed'] || $x['previous'])->sortByDesc('closed')->values();

        // weekly buckets of created vs closed across the current period
        $weekly = [];
        for ($d = $from->copy()->startOfDay(); $d <= $to; $d->addWeek()) {
            $e = $d->copy()->addDays(6)->endOfDay(); if ($e > $to) $e = $to->copy();
            $weekly[] = ['label' => $d->format('d M'), 'created' => $this->leads()->whereBetween('leads.created_at', [$d->toDateTimeString(), $e->toDateTimeString()])->count(),
                'closed' => $this->leads()->whereBetween('leads.enrolled_at', [$d->toDateTimeString(), $e->toDateTimeString()])->count()];
            if (count($weekly) >= 26) break;
        }

        return response()->json(['from' => $from->toDateString(), 'to' => $to->toDateString(), 'previous' => ['from' => $pFrom->toDateString(), 'to' => $pTo->toDateString()],
            'metrics' => $cmp, 'leaderboard' => $leaders, 'weekly' => $weekly]);
    }

    // GET /leads/reports/channels?from&to — every channel (Meta Ads, WA Cloud, WA Chat, Instagram, Messenger, Website widget…) with its origins
    // (the specific number / account / campaign) and a category × channel grid. One shape for all channels so they compare side by side.
    public function channels(Request $r): JsonResponse
    {
        [$from, $to] = $this->range($r);
        $f = $from->toDateTimeString(); $t = $to->toDateTimeString(); $cid = $this->cid();

        $money = DB::table('lead_installment_payments as p')->join('leads', 'leads.id', '=', 'p.lead_id')->where('p.company_id', $cid)->whereNull('p.reversed_at')
            ->whereBetween('leads.created_at', [$f, $t])->whereNull('leads.deleted_at')
            ->when(! $this->team(), fn ($q) => $q->whereIn('leads.assigned_to', auth()->user()->leadOwnerIds()))
            ->selectRaw("COALESCE(NULLIF(leads.source, ''), 'manual') source, COALESCE(NULLIF(leads.origin_label, ''), CASE WHEN leads.wa_open_campaign_id IS NOT NULL THEN CONCAT('Campaign #', leads.wa_open_campaign_id) ELSE '' END) origin, SUM(p.amount + p.late_fee_amount) amt")
            ->groupBy('source', 'origin')->get();
        $revBySource = $money->groupBy('source')->map(fn ($g) => (float) $g->sum('amt'));
        $revByOrigin = $money->mapWithKeys(fn ($x) => [$x->source.'|'.$x->origin => (float) $x->amt]);

        $base = fn () => $this->leads()->whereBetween('leads.created_at', [$f, $t]);
        $metrics = "COUNT(*) total, SUM(leads.stage = 'enrolled') enrolled, SUM(leads.stage = 'lost') lost, SUM(leads.stage NOT IN ('enrolled','lost')) active";
        $src = "COALESCE(NULLIF(leads.source, ''), 'manual')";
        $shape = fn ($x, float $rev) => ['total' => (int) $x->total, 'enrolled' => (int) $x->enrolled, 'lost' => (int) $x->lost, 'active' => (int) $x->active,
            'conversion' => $x->total ? round($x->enrolled / $x->total * 100, 1) : 0, 'revenue' => round($rev, 2)];

        $origins = $base()->selectRaw("$src source, COALESCE(NULLIF(leads.origin_label, ''), CASE WHEN leads.wa_open_campaign_id IS NOT NULL THEN CONCAT('Campaign #', leads.wa_open_campaign_id) ELSE '' END) origin, MAX(leads.origin_type) origin_type, $metrics")
            ->groupBy('source', 'origin')->orderByDesc('total')->get()->groupBy('source');

        $rows = $base()->selectRaw("$src source, $metrics")->groupBy('source')->orderByDesc('total')->get()->map(function ($x) use ($shape, $revBySource, $revByOrigin, $origins) {
            return ['source' => $x->source, 'label' => config("lead_sources.{$x->source}.label") ?? ucwords(str_replace('_', ' ', (string) $x->source)), 'icon' => config("lead_sources.{$x->source}.icon"),
                    ...$shape($x, $revBySource[$x->source] ?? 0),
                    'origins' => ($origins[$x->source] ?? collect())->map(fn ($o) => ['label' => $o->origin !== '' ? $o->origin : 'Not specified', 'type' => $o->origin_type,
                        ...$shape($o, $revByOrigin[$x->source.'|'.$o->origin] ?? 0)])->values()->take(25)->all()];
        })->values();

        $topCats = $base()->selectRaw("COALESCE(NULLIF(leads.category, ''), 'Uncategorised') k, COUNT(*) n")->groupBy('k')->orderByDesc('n')->limit(12)->pluck('n', 'k');
        $grid = $base()->selectRaw("COALESCE(NULLIF(leads.category, ''), 'Uncategorised') cat, $src source, COUNT(*) n")->groupBy('cat', 'source')->get()
            ->filter(fn ($x) => $topCats->has($x->cat))->groupBy('cat')
            ->map(fn ($g, $cat) => ['category' => $cat, 'total' => (int) $topCats[$cat], 'by_source' => $g->pluck('n', 'source')->map(fn ($n) => (int) $n)->all()])
            ->sortByDesc('total')->values();

        return response()->json(['from' => $from->toDateString(), 'to' => $to->toDateString(), 'rows' => $rows, 'category_grid' => $grid,
            'totals' => ['total' => $rows->sum('total'), 'enrolled' => $rows->sum('enrolled'), 'lost' => $rows->sum('lost'), 'revenue' => round($rows->sum('revenue'), 2)]]);
    }
}
