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
}
