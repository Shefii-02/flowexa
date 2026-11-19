<?php

namespace App\Modules\Hr\Support;

use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\User;
use App\Modules\Hr\Models\HrStaffProfile;
use App\Modules\Hr\Models\SalesActivityTarget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Daily + monthly sales-activity numbers per staff member: calls (new-lead vs follow-up), conversions,
 * targets and progress, and what each converted lead cost.
 *
 *   new-lead call   = the first logged activity on a lead (LeadActivity.is_first_contact)
 *   follow-up call  = any later call on it
 *   conversion      = a lead that reached "enrolled" (Lead.enrolled_at), credited to whoever owns it
 *   cost / conversion = (cost of the leads they received that month + their pro-rated salary) / conversions
 */
class SalesActivityMetrics
{
    /** Active employees who work leads: can manage leads, own a lead, or have logged an activity. */
    public function salesStaff(int $companyId, ?array $onlyIds = null): Collection
    {
        return User::where('company_id', $companyId)->where('is_active', true)
            ->when($onlyIds, fn ($q) => $q->whereIn('id', $onlyIds))
            ->when(!$onlyIds, fn ($q) => $q->where(function ($q) {
                $q->whereHas('role', fn ($r) => $r->whereJsonContains('permissions', 'leads.manage'))
                  ->orWhereExists(fn ($x) => $x->select(DB::raw(1))->from('leads')->whereColumn('leads.assigned_to', 'users.id'))
                  ->orWhereExists(fn ($x) => $x->select(DB::raw(1))->from('lead_activities')->whereColumn('lead_activities.user_id', 'users.id'));
            }))
            ->with('role:id,name')->orderBy('name')->get(['id', 'name', 'email', 'department', 'role_id']);
    }

    /** Effective targets for one employee: their own non-zero values, else the company default. */
    public function targetsFor(int $companyId, ?int $userId, ?Collection $rows = null): array
    {
        $rows ??= SalesActivityTarget::where('company_id', $companyId)->get();
        $default = $rows->first(fn ($r) => $r->user_id === null);
        $mine = $userId ? $rows->first(fn ($r) => (int) $r->user_id === $userId) : null;
        $out = [];
        foreach (SalesActivityTarget::FIELDS as $f) {
            $own = $mine?->{$f} ?? 0;
            $out[$f] = $own > 0 ? $own : ($default?->{$f} ?? 0);
        }
        return $out;
    }

    /** @return array{date:string, month:string, staff:array<int,array>, totals:array} */
    public function team(int $companyId, Carbon $day, ?array $onlyIds = null): array
    {
        $staff = $this->salesStaff($companyId, $onlyIds);
        $ids = $staff->pluck('id')->all();
        $dayStart = $day->copy()->startOfDay(); $dayEnd = $day->copy()->endOfDay();
        $monthStart = $day->copy()->startOfMonth(); $monthEnd = $day->copy()->endOfMonth();

        $d = $this->activityStats($companyId, $ids, $dayStart, $dayEnd);
        $m = $this->activityStats($companyId, $ids, $monthStart, $monthEnd);

        $assignedDay = $this->leadCount($companyId, $ids, 'assigned_at', $dayStart, $dayEnd);
        $assignedMonth = $this->leadCount($companyId, $ids, 'assigned_at', $monthStart, $monthEnd);
        $convDay = $this->leadCount($companyId, $ids, 'enrolled_at', $dayStart, $dayEnd);
        $convMonth = $this->leadCount($companyId, $ids, 'enrolled_at', $monthStart, $monthEnd);
        $lostDay = $this->leadCount($companyId, $ids, 'lost_at', $dayStart, $dayEnd);
        $lostMonth = $this->leadCount($companyId, $ids, 'lost_at', $monthStart, $monthEnd);

        $open = Lead::where('company_id', $companyId)->whereIn('assigned_to', $ids)->whereNotIn('stage', ['enrolled', 'lost'])
            ->selectRaw('assigned_to, stage, COUNT(*) c')->groupBy('assigned_to', 'stage')->get()->groupBy('assigned_to');
        $due = DB::table('lead_activities as a')->join('leads as l', 'l.id', '=', 'a.lead_id')
            ->where('a.company_id', $companyId)->whereIn('l.assigned_to', $ids)->whereNotIn('l.stage', ['enrolled', 'lost'])
            ->whereBetween('a.next_follow_up_at', [$dayStart, $dayEnd])
            ->selectRaw('l.assigned_to, COUNT(DISTINCT l.id) c')->groupBy('l.assigned_to')->pluck('c', 'assigned_to');

        $defaultRow = SalesActivityTarget::where('company_id', $companyId)->whereNull('user_id')->first();
        $defaultCost = (float) ($defaultRow?->default_lead_cost ?? 0);
        $leadCost = Lead::where('company_id', $companyId)->whereIn('assigned_to', $ids)->whereBetween('assigned_at', [$monthStart, $monthEnd])
            ->selectRaw('assigned_to, SUM(COALESCE(cost, ?)) c', [$defaultCost])->groupBy('assigned_to')->pluck('c', 'assigned_to');
        $salary = HrStaffProfile::where('company_id', $companyId)->whereIn('user_id', $ids)->pluck('monthly_salary', 'user_id');
        $targetRows = SalesActivityTarget::where('company_id', $companyId)->get();

        $today = Carbon::now();
        $elapsed = $monthEnd->lt($today) ? $monthStart->daysInMonth : ($monthStart->gt($today) ? 0 : min($today->day, $monthStart->daysInMonth));
        $fraction = $monthStart->daysInMonth > 0 ? $elapsed / $monthStart->daysInMonth : 0;

        $rows = $staff->map(function (User $u) use ($d, $m, $assignedDay, $assignedMonth, $convDay, $convMonth, $lostDay, $lostMonth, $open, $due, $leadCost, $salary, $targetRows, $companyId, $fraction) {
            $t = $this->targetsFor($companyId, $u->id, $targetRows);
            $stages = collect($open->get($u->id, []))->pluck('c', 'stage');
            $cDay = (int) ($convDay[$u->id] ?? 0); $cMonth = (int) ($convMonth[$u->id] ?? 0);
            $leadCostM = round((float) ($leadCost[$u->id] ?? 0), 2);
            $staffCost = round((float) ($salary[$u->id] ?? 0) * $fraction, 2);
            $totalCost = $leadCostM + $staffCost;
            $costPer = $cMonth > 0 ? round($totalCost / $cMonth, 2) : null;
            $pct = fn ($done, $target) => $target > 0 ? (int) min(999, round($done / $target * 100)) : null;
            $dd = $d[$u->id] ?? $this->emptyStats(); $mm = $m[$u->id] ?? $this->emptyStats();

            return [
                'id' => $u->id, 'name' => $u->name, 'role' => $u->role?->name, 'department' => $u->department,
                'day' => $dd + [
                    'assigned' => (int) ($assignedDay[$u->id] ?? 0), 'converted' => $cDay, 'lost' => (int) ($lostDay[$u->id] ?? 0),
                    'follow_ups_due' => (int) ($due[$u->id] ?? 0),
                    'new_calls_pct' => $pct($dd['new_calls'], $t['daily_new_lead_calls']), 'follow_up_calls_pct' => $pct($dd['follow_up_calls'], $t['daily_follow_up_calls']),
                ],
                'month' => $mm + [
                    'assigned' => (int) ($assignedMonth[$u->id] ?? 0), 'converted' => $cMonth, 'lost' => (int) ($lostMonth[$u->id] ?? 0),
                    'calls_pct' => $pct($mm['calls'], $t['monthly_calls']), 'conversions_pct' => $pct($cMonth, $t['monthly_conversions']),
                    'lead_cost' => $leadCostM, 'staff_cost' => $staffCost, 'total_cost' => round($totalCost, 2), 'cost_per_conversion' => $costPer,
                    'cost_over_limit' => $costPer !== null && $t['max_cost_per_conversion'] > 0 && $costPer > $t['max_cost_per_conversion'],
                ],
                'pipeline' => ['new' => (int) ($stages['new'] ?? 0), 'contacted' => (int) ($stages['contacted'] ?? 0), 'follow_up' => (int) ($stages['follow_up'] ?? 0)],
                'targets' => $t,
            ];
        })->values();

        $sum = fn (string $scope, string $k) => $rows->sum(fn ($r) => $r[$scope][$k] ?? 0);
        return [
            'date' => $day->toDateString(), 'month' => $day->format('Y-m'),
            'staff' => $rows->all(),
            'totals' => [
                'day' => ['new_calls' => $sum('day', 'new_calls'), 'follow_up_calls' => $sum('day', 'follow_up_calls'), 'calls' => $sum('day', 'calls'), 'assigned' => $sum('day', 'assigned'), 'converted' => $sum('day', 'converted')],
                'month' => ['calls' => $sum('month', 'calls'), 'converted' => $sum('month', 'converted'), 'total_cost' => round($sum('month', 'total_cost'), 2)],
            ],
        ];
    }

    /** One employee: their numbers, the day's activity timeline, and a per-day trend for the month. */
    public function staffDetail(int $companyId, int $userId, Carbon $day): ?array
    {
        $row = collect($this->team($companyId, $day, [$userId])['staff'])->first();
        if (!$row) {
            return null;
        }
        $dayStart = $day->copy()->startOfDay(); $dayEnd = $day->copy()->endOfDay();
        $monthStart = $day->copy()->startOfMonth(); $monthEnd = $day->copy()->endOfMonth();

        $timeline = LeadActivity::where('company_id', $companyId)->where('user_id', $userId)->whereBetween('happened_at', [$dayStart, $dayEnd])
            ->with(['lead:id,contact_id,stage', 'lead.contact:id,name,phone'])->orderBy('happened_at')->get()
            ->map(fn ($a) => [
                'id' => $a->id, 'at' => $a->happened_at->toIso8601String(), 'kind' => $a->kind, 'outcome' => $a->outcome, 'first' => $a->is_first_contact,
                'duration_seconds' => $a->duration_seconds, 'notes' => $a->notes, 'next_follow_up_at' => $a->next_follow_up_at?->toIso8601String(),
                'lead_id' => $a->lead_id, 'contact' => $a->lead?->contact?->name ?: $a->lead?->contact?->phone, 'lead_stage' => $a->lead?->stage,
            ])->values();

        $byDay = LeadActivity::where('company_id', $companyId)->where('user_id', $userId)->where('kind', 'call')->whereBetween('happened_at', [$monthStart, $monthEnd])
            ->selectRaw('DATE(happened_at) d, SUM(is_first_contact) new_calls, SUM(1 - is_first_contact) follow_up_calls')->groupBy('d')->get()->keyBy('d');
        $conv = Lead::where('company_id', $companyId)->where('assigned_to', $userId)->whereBetween('enrolled_at', [$monthStart, $monthEnd])
            ->selectRaw('DATE(enrolled_at) d, COUNT(*) c')->groupBy('d')->pluck('c', 'd');
        $trend = [];
        for ($c = $monthStart->copy(); $c->lte($monthEnd); $c->addDay()) {
            $k = $c->toDateString();
            $trend[] = ['date' => $k, 'new_calls' => (int) ($byDay[$k]->new_calls ?? 0), 'follow_up_calls' => (int) ($byDay[$k]->follow_up_calls ?? 0), 'converted' => (int) ($conv[$k] ?? 0)];
        }

        return $row + ['timeline' => $timeline, 'trend' => $trend, 'date' => $day->toDateString()];
    }

    /** @return array<int,array> user_id => stats */
    private function activityStats(int $companyId, array $ids, Carbon $from, Carbon $to): array
    {
        $rows = LeadActivity::where('company_id', $companyId)->whereIn('user_id', $ids)->whereBetween('happened_at', [$from, $to])
            ->selectRaw("user_id,
                SUM(kind='call') calls,
                SUM(kind='call' AND is_first_contact=1) new_calls,
                SUM(kind='call' AND is_first_contact=0) follow_up_calls,
                SUM(kind='call' AND outcome IN ('connected','interested','callback')) connected,
                SUM(kind <> 'call') other_activities,
                COALESCE(SUM(CASE WHEN kind='call' THEN duration_seconds END),0) talk_seconds,
                COUNT(DISTINCT CASE WHEN kind='call' AND is_first_contact=0 THEN lead_id END) leads_followed_up")
            ->groupBy('user_id')->get();
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->user_id] = [
                'calls' => (int) $r->calls, 'new_calls' => (int) $r->new_calls, 'follow_up_calls' => (int) $r->follow_up_calls,
                'connected' => (int) $r->connected, 'other_activities' => (int) $r->other_activities,
                'talk_minutes' => (int) round($r->talk_seconds / 60), 'leads_followed_up' => (int) $r->leads_followed_up,
            ];
        }
        return $out;
    }

    private function emptyStats(): array
    {
        return ['calls' => 0, 'new_calls' => 0, 'follow_up_calls' => 0, 'connected' => 0, 'other_activities' => 0, 'talk_minutes' => 0, 'leads_followed_up' => 0];
    }

    private function leadCount(int $companyId, array $ids, string $col, Carbon $from, Carbon $to)
    {
        return Lead::where('company_id', $companyId)->whereIn('assigned_to', $ids)->whereBetween($col, [$from, $to])
            ->selectRaw('assigned_to, COUNT(*) c')->groupBy('assigned_to')->pluck('c', 'assigned_to');
    }
}
