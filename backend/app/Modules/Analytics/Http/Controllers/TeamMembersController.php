<?php

namespace App\Modules\Analytics\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\CrmDeal;
use App\Models\CrmTask;
use App\Models\Lead;
use App\Models\User;
use App\Modules\Hr\Models\HrAttendance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * GET /analytics/team-members?section=hr|crm|leads — the "Team" block on the HR, CRM and Lead dashboards: EVERY active member of the
 * company with their own figures, for the company admin (owner / admin), a team head (team_lead) or anyone with HR / staff-view
 * permission. Everyone else gets 403 — they see only their own numbers elsewhere on the dashboard.
 */
class TeamMembersController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $section = $request->validate(['section' => 'required|in:hr,crm,leads'])['section'];
        $u = auth()->user()->loadMissing('role');
        abort_unless(self::canSeeTeam($u), 403, 'Only the company admin or a team head can see the whole team.');

        $companyId = (int) $u->company_id;
        $people = User::where('company_id', $companyId)->where('is_active', true)->with('role:id,name,label')
            ->orderBy('name')->get(['id', 'name', 'avatar', 'department', 'role_id']);
        $ids = $people->pluck('id')->all();

        [$figures, $summary] = match ($section) {
            'hr'    => $this->hr($companyId, $ids),
            'crm'   => $this->crm($companyId, $ids),
            'leads' => $this->leads($companyId, $ids),
        };

        return response()->json([
            'section' => $section,
            'summary' => $summary,
            'members' => $people->map(fn (User $p) => [
                'id'         => $p->id,
                'name'       => $p->name,
                'avatar'     => $p->avatar,
                'department' => $p->department,
                'role'       => $p->role?->label ?: $p->role?->name,
                'is_me'      => $p->id === $u->id,
            ] + $figures[$p->id])->values(),
        ]);
    }

    public static function canSeeTeam(User $u): bool
    {
        return $u->isSuperAdmin() || $u->isOwner()
            || in_array($u->role?->name, ['admin', 'team_lead'], true)
            || $u->hasAnyPermission(['hr.manage', 'hr.attendance.view_all', 'staff.view']);
    }

    /** Today's attendance: working / out / on_leave / not_in, with lateness. */
    private function hr(int $companyId, array $ids): array
    {
        $rows = HrAttendance::where('company_id', $companyId)->whereDate('work_date', today())->get()->keyBy('user_id');
        $figures = [];
        $sum = ['working' => 0, 'out' => 0, 'on_leave' => 0, 'not_in' => 0, 'late' => 0];
        foreach ($ids as $id) {
            $r = $rows[$id] ?? null;
            $state = match (true) {
                $r?->status === 'on_leave'                   => 'on_leave',
                $r?->clock_in_at && !$r->clock_out_at         => 'working',
                (bool) $r?->clock_out_at                      => 'out',
                default                                       => 'not_in',
            };
            $late = (int) ($r?->late_minutes ?? 0);
            $sum[$state]++;
            if ($late > 0) { $sum['late']++; }
            $figures[$id] = [
                'state'         => $state,
                'clock_in_at'   => $r?->clock_in_at,
                'clock_out_at'  => $r?->clock_out_at,
                'late_minutes'  => $late,
            ];
        }

        return [$figures, $sum + ['total' => count($ids)]];
    }

    /** Deals owned + tasks assigned. */
    private function crm(int $companyId, array $ids): array
    {
        $deals = CrmDeal::where('company_id', $companyId)->whereNotNull('owner_id')
            ->select('owner_id', DB::raw("SUM(status = 'open') as open_deals"), DB::raw("COALESCE(SUM(CASE WHEN status = 'open' THEN value END),0) as open_value"),
                DB::raw("SUM(status = 'won' AND closed_at >= '".now()->startOfMonth()->toDateTimeString()."') as won_month"))
            ->groupBy('owner_id')->get()->keyBy('owner_id');
        $tasks = CrmTask::where('company_id', $companyId)->whereNotNull('assigned_to')->where('status', 'open')
            ->select('assigned_to', DB::raw('COUNT(*) as open_tasks'), DB::raw("SUM(due_at IS NOT NULL AND due_at < '".now()->toDateTimeString()."') as overdue_tasks"))
            ->groupBy('assigned_to')->get()->keyBy('assigned_to');

        $figures = [];
        $sum = ['open_deals' => 0, 'open_value' => 0.0, 'won_month' => 0, 'open_tasks' => 0, 'overdue_tasks' => 0];
        foreach ($ids as $id) {
            $d = $deals[$id] ?? null; $t = $tasks[$id] ?? null;
            $figures[$id] = [
                'open_deals'    => (int) ($d->open_deals ?? 0),
                'open_value'    => (float) ($d->open_value ?? 0),
                'won_month'     => (int) ($d->won_month ?? 0),
                'open_tasks'    => (int) ($t->open_tasks ?? 0),
                'overdue_tasks' => (int) ($t->overdue_tasks ?? 0),
            ];
            foreach ($sum as $k => $_) { $sum[$k] += $figures[$id][$k]; }
        }

        return [$figures, $sum + ['total' => count($ids)]];
    }

    /** Leads assigned to each person. */
    private function leads(int $companyId, array $ids): array
    {
        $rows = Lead::where('company_id', $companyId)->whereNotNull('assigned_to')
            ->select('assigned_to', DB::raw('COUNT(*) as assigned'),
                DB::raw("SUM(stage NOT IN ('enrolled','lost')) as active"),
                DB::raw("SUM(stage = 'enrolled') as enrolled"),
                DB::raw("SUM(stage = 'lost') as lost"),
                DB::raw("SUM(created_at >= '".now()->startOfDay()->toDateTimeString()."') as new_today"))
            ->groupBy('assigned_to')->get()->keyBy('assigned_to');

        $figures = [];
        $sum = ['assigned' => 0, 'active' => 0, 'enrolled' => 0, 'lost' => 0, 'new_today' => 0];
        foreach ($ids as $id) {
            $r = $rows[$id] ?? null;
            $assigned = (int) ($r->assigned ?? 0);
            $figures[$id] = [
                'assigned'   => $assigned,
                'active'     => (int) ($r->active ?? 0),
                'enrolled'   => (int) ($r->enrolled ?? 0),
                'lost'       => (int) ($r->lost ?? 0),
                'new_today'  => (int) ($r->new_today ?? 0),
                'conversion' => $assigned > 0 ? round(((int) $r->enrolled) / $assigned * 100, 1) : 0.0,
            ];
            foreach ($sum as $k => $_) { $sum[$k] += $figures[$id][$k]; }
        }
        $sum['conversion'] = $sum['assigned'] > 0 ? round($sum['enrolled'] / $sum['assigned'] * 100, 1) : 0.0;

        return [$figures, $sum + ['total' => count($ids)]];
    }
}
