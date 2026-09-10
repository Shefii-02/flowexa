<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Models\HrAttendance;
use App\Modules\Hr\Models\HrLeaveRequest;
use App\Modules\Hr\Models\HrLeaveType;
use App\Modules\Hr\Models\HrSetting;
use App\Modules\Hr\Support\AttendanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LeaveController extends Controller
{
    public function __construct(private readonly AttendanceService $svc) {}

    private function companyId(): int
    {
        return (int) auth()->user()->company_id;
    }

    private function canApprove(): bool
    {
        $u = auth()->user();
        return $u->isOwner() || $u->isSuperAdmin() || $u->hasAnyPermission(['hr.leave.approve', 'hr.manage']);
    }

    public function types(): JsonResponse
    {
        return response()->json(['data' => HrLeaveType::where('company_id', $this->companyId())
            ->where('is_active', true)->orderBy('sort_order')->get()]);
    }

    /** GET /hr/leave?scope=mine|team&status= */
    public function index(Request $request): JsonResponse
    {
        $q = HrLeaveRequest::where('company_id', $this->companyId())
            ->with('leaveType:id,name,color', 'user:id,name,avatar', 'reviewer:id,name')
            ->when($request->filled('status'), fn ($x) => $x->where('status', $request->string('status')))
            // Day filter: requests that cover this date (start_date .. end_date span it).
            ->when($request->filled('date'), fn ($x) => $x
                ->whereDate('start_date', '<=', $request->date('date'))
                ->whereDate('end_date', '>=', $request->date('date')))
            // Range filter: requests overlapping [from, to].
            ->when($request->filled('from'), fn ($x) => $x->whereDate('end_date', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($x) => $x->whereDate('start_date', '<=', $request->date('to')))
            ->orderByDesc('start_date');

        if ($request->string('scope') === 'team' && $this->canApprove()) {
            $q->when($request->filled('user_id'), fn ($x) => $x->where('user_id', $request->integer('user_id')));
        } else {
            $q->where('user_id', auth()->id());
        }

        return response()->json($q->paginate(50));
    }

    /** POST /hr/leave */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'leave_type_id' => ['required', Rule::exists('hr_leave_types', 'id')->where('company_id', $this->companyId())],
            'start_date'    => 'required|date',
            'end_date'      => 'required|date|after_or_equal:start_date',
            'half_day'      => 'boolean',
            'reason'        => 'nullable|string|max:1000',
        ]);

        $start = Carbon::parse($data['start_date'])->startOfDay();
        $end = Carbon::parse($data['end_date'])->startOfDay();
        $days = ($data['half_day'] ?? false) ? 0.5 : ($start->diffInDays($end) + 1);

        $type = HrLeaveType::where('company_id', $this->companyId())->findOrFail($data['leave_type_id']);
        $settings = HrSetting::forCompany($this->companyId());
        $autoApprove = !$type->requires_approval || $settings->leave_auto_approve;

        $leave = HrLeaveRequest::create([
            'company_id'    => $this->companyId(),
            'user_id'       => auth()->id(),
            'leave_type_id' => $type->id,
            'start_date'    => $start,
            'end_date'      => $end,
            'days'          => $days,
            'half_day'      => (bool) ($data['half_day'] ?? false),
            'reason'        => $data['reason'] ?? null,
            'status'        => $autoApprove ? 'approved' : 'pending',
            'reviewed_by'   => $autoApprove ? auth()->id() : null,
            'reviewed_at'   => $autoApprove ? now() : null,
        ]);

        if ($autoApprove) {
            $this->applyToAttendance($leave);
        }

        return response()->json(['data' => $leave->load('leaveType')], 201);
    }

    /** POST /hr/leave/{id}/cancel — requester withdraws a pending / future leave. */
    public function cancel(int $id): JsonResponse
    {
        $leave = HrLeaveRequest::where('company_id', $this->companyId())
            ->where('user_id', auth()->id())->findOrFail($id);

        if ($leave->status === 'rejected' || $leave->end_date->isPast()) {
            throw ValidationException::withMessages(['leave' => 'This leave can no longer be cancelled.']);
        }

        $leave->update(['status' => 'cancelled']);
        $this->clearFromAttendance($leave);

        return response()->json(['data' => $leave->fresh()]);
    }

    /** POST /hr/leave/{id}/review — approve / reject (permission-gated). */
    public function review(Request $request, int $id): JsonResponse
    {
        abort_unless($this->canApprove(), 403, 'No leave-approval permission.');

        $data = $request->validate([
            'decision' => 'required|in:approved,rejected',
            'note'     => 'nullable|string|max:500',
        ]);

        $leave = HrLeaveRequest::where('company_id', $this->companyId())->findOrFail($id);
        $leave->update([
            'status'      => $data['decision'],
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
            'review_note' => $data['note'] ?? null,
        ]);

        if ($data['decision'] === 'approved') {
            $this->applyToAttendance($leave);
        } else {
            $this->clearFromAttendance($leave);
        }

        return response()->json(['data' => $leave->fresh()->load('leaveType', 'reviewer:id,name')]);
    }

    // ── helpers ────────────────────────────────────────────────────────────

    /** Mark each day of the leave as on_leave in hr_attendance. */
    private function applyToAttendance(HrLeaveRequest $leave): void
    {
        for ($d = $leave->start_date->copy(); $d->lte($leave->end_date); $d->addDay()) {
            $att = HrAttendance::firstOrNew([
                'company_id' => $leave->company_id,
                'user_id'    => $leave->user_id,
                'work_date'  => $d->toDateString(),
            ]);
            if ($att->clock_in_at) {
                continue; // already worked that day — leave the record alone
            }
            $att->fill([
                'status'       => $leave->half_day ? 'half_day' : 'on_leave',
                'note_color'   => $leave->leaveType->color ?? 'info',
                'note_message' => 'On leave: ' . $leave->leaveType->name,
            ])->save();
        }
    }

    private function clearFromAttendance(HrLeaveRequest $leave): void
    {
        HrAttendance::where('company_id', $leave->company_id)
            ->where('user_id', $leave->user_id)
            ->whereBetween('work_date', [$leave->start_date, $leave->end_date])
            ->whereIn('status', ['on_leave', 'half_day'])
            ->whereNull('clock_in_at')
            ->update(['status' => 'absent', 'note_color' => null, 'note_message' => null]);
    }
}
