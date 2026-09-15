<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\CrmTask;
use App\Models\Lead;
use App\Models\ListingSale;
use App\Models\User;
use App\Modules\Hr\Models\HrAttendance;
use App\Modules\Hr\Models\HrStaffProfile;
use App\Modules\Hr\Support\AttendanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

/**
 * Single aggregate read for the sales-rep home screen (mobile app + web) — one round trip
 * instead of the client stitching together attendance, sales, leads, pipeline, follow-ups
 * and tasks from five-plus separate endpoints.
 */
class DashboardController extends Controller
{
    public function __construct(private readonly AttendanceService $attendanceSvc) {}

    /** GET /hr/dashboard/me */
    public function me(): JsonResponse
    {
        /** @var User $user */
        $user = auth()->user();
        $companyId = (int) $user->company_id;
        $userId = (int) $user->id;

        return response()->json([
            'greeting'     => $this->greeting($user),
            'attendance'   => $this->attendance($companyId, $userId),
            'sales_target' => $this->salesTarget($companyId, $userId),
            'streak'       => ['days' => $this->attendanceSvc->dayStreak($companyId, $userId)],
            'rank'         => $this->rank($companyId, $userId),
            'leads'        => ['new_today' => Lead::where('company_id', $companyId)
                ->where('assigned_to', $userId)->whereDate('created_at', today())->count()],
            'pipeline'     => $this->pipeline($companyId, $userId),
            'follow_ups'   => $this->followUps($companyId, $userId),
            'tasks'        => $this->tasks($companyId, $userId),
            'next_up'      => $this->nextUp($companyId, $userId),
        ]);
    }

    private function greeting(User $user): array
    {
        $hour = now($user->timezone ?? null)->hour;
        $part = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
        $initials = collect(explode(' ', trim((string) $user->name)))
            ->filter()->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->take(2)->implode('');

        return [
            'part'     => $part,
            'name'     => $user->name,
            'initials' => $initials ?: '?',
            'avatar'   => $user->avatar,
        ];
    }

    private function attendance(int $companyId, int $userId): array
    {
        $today = HrAttendance::where('company_id', $companyId)->where('user_id', $userId)
            ->whereDate('work_date', today())->with('breaks.breakType')->first();

        $status = 'not_clocked_in';
        if ($today?->clock_in_at && !$today->clock_out_at) {
            $status = $today->openBreak() ? 'on_break' : 'clocked_in';
        } elseif ($today?->clock_out_at) {
            $status = 'clocked_out';
        }

        $lastBreak = $today?->breaks->sortByDesc('id')->first();

        return [
            'status'          => $status,
            'clock_in_at'     => $today?->clock_in_at,
            'clock_out_at'    => $today?->clock_out_at,
            'open_break'      => $today?->openBreak(),
            'last_break_in'   => $lastBreak?->start_at,
            'last_break_out'  => $lastBreak?->end_at,
            'worked_minutes'  => $today ? $this->attendanceSvc->liveWorkedMinutes($today) : 0,
            'break_minutes'   => (int) ($today?->break_minutes ?? 0),
        ];
    }

    private function salesTarget(int $companyId, int $userId): array
    {
        $month = now()->format('Y-m');
        $profile = HrStaffProfile::forUser($companyId, $userId);
        $target = (float) ($profile->monthly_target ?? 0);

        $achieved = (float) ListingSale::where('company_id', $companyId)
            ->where('staff_id', $userId)
            ->whereYear('sold_at', now()->year)->whereMonth('sold_at', now()->month)
            ->sum('amount');

        return [
            'month'    => $month,
            'target'   => $target,
            'achieved' => round($achieved, 2),
            'progress' => $target > 0 ? round(min($achieved / $target * 100, 999), 1) : null,
            'currency' => 'INR',
        ];
    }

    private function rank(int $companyId, int $userId): array
    {
        $sold = ListingSale::where('company_id', $companyId)
            ->whereYear('sold_at', now()->year)->whereMonth('sold_at', now()->month)
            ->selectRaw('staff_id, SUM(amount) as total')
            ->groupBy('staff_id')
            ->pluck('total', 'staff_id');

        $staffIds = HrStaffProfile::where('company_id', $companyId)->where('is_active', true)->pluck('user_id');

        $ranked = $staffIds->mapWithKeys(fn ($id) => [$id => (float) ($sold[$id] ?? 0)])
            ->sortByDesc(fn ($total) => $total)
            ->keys()
            ->values();

        $position = $ranked->search($userId);

        return [
            'position'    => $position === false ? null : $position + 1,
            'total_staff' => $ranked->count(),
        ];
    }

    private function pipeline(int $companyId, int $userId): array
    {
        $counts = Lead::where('company_id', $companyId)->where('assigned_to', $userId)
            ->selectRaw('stage, COUNT(*) as c')->groupBy('stage')->pluck('c', 'stage');

        return [
            'new'       => (int) ($counts['new'] ?? 0),
            'contacted' => (int) ($counts['contacted'] ?? 0) + (int) ($counts['follow_up'] ?? 0),
            'won'       => (int) ($counts['enrolled'] ?? 0),
        ];
    }

    /** Contact-outreach tasks (call/whatsapp/email) that carry a due date. */
    private function followUpsQuery(int $companyId, int $userId)
    {
        return CrmTask::where('company_id', $companyId)->where('assigned_to', $userId)
            ->where('status', 'open')
            ->whereIn('type', ['call', 'whatsapp', 'email'])
            ->whereNotNull('due_at');
    }

    private function followUpBucket(Carbon $dueAt): string
    {
        if ($dueAt->isToday()) {
            return $dueAt->isPast() ? 'missed' : 'due_today';
        }

        return $dueAt->lt(today()) ? 'late' : 'upcoming';
    }

    private function followUps(int $companyId, int $userId): array
    {
        $rows = $this->followUpsQuery($companyId, $userId)
            ->with('contact:id,name,phone')
            ->orderBy('due_at')
            ->limit(20)
            ->get();

        $counts = ['missed' => 0, 'late' => 0, 'due_today' => 0];
        $items = [];

        foreach ($rows as $row) {
            $bucket = $this->followUpBucket($row->due_at);
            if (isset($counts[$bucket])) {
                $counts[$bucket]++;
            }
            $items[] = [
                'id'           => $row->id,
                'title'        => $row->title,
                'type'         => $row->type,
                'contact_name' => $row->contact?->name,
                'contact_phone'=> $row->contact?->phone,
                'due_at'       => $row->due_at,
                'bucket'       => $bucket,
                'days_late'    => $bucket === 'late' ? today()->diffInDays($row->due_at->copy()->startOfDay()) : 0,
            ];
        }

        return ['counts' => $counts, 'items' => $items];
    }

    private function tasks(int $companyId, int $userId): array
    {
        $rows = CrmTask::where('company_id', $companyId)->where('assigned_to', $userId)
            ->whereIn('type', ['todo', 'meeting'])
            ->where(function ($q) {
                $q->where('status', 'done')->whereDate('completed_at', today())
                    ->orWhere('status', 'open');
            })
            ->orderByRaw("CASE WHEN status = 'open' THEN 0 ELSE 1 END")
            ->orderByRaw('due_at IS NULL, due_at ASC')
            ->get(['id', 'title', 'status', 'priority', 'due_at']);

        return [
            'done'  => $rows->where('status', 'done')->count(),
            'total' => $rows->count(),
            'items' => $rows->values(),
        ];
    }

    private function nextUp(int $companyId, int $userId): ?array
    {
        $task = CrmTask::where('company_id', $companyId)->where('assigned_to', $userId)
            ->where('status', 'open')->whereNotNull('due_at')->where('due_at', '>=', now())
            ->with('contact:id,name')
            ->orderBy('due_at')
            ->first();

        if (!$task) {
            return null;
        }

        return [
            'title'    => $task->contact ? "{$task->contact->name} · {$task->title}" : $task->title,
            'subtitle' => ucfirst($task->type),
            'at'       => $task->due_at,
        ];
    }
}
