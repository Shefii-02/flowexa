<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Models\HrAttendance;
use App\Modules\Hr\Models\HrBreakSession;
use App\Modules\Hr\Models\HrBreakType;
use App\Modules\Hr\Models\HrSetting;
use App\Modules\Hr\Models\HrStaffProfile;
use App\Modules\Hr\Support\AttendanceService;
use App\Modules\Hr\Support\Geo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class AttendanceController extends Controller
{
    public function __construct(private readonly AttendanceService $svc) {}

    private function companyId(): int
    {
        return (int) auth()->user()->company_id;
    }

    private function canManage(): bool
    {
        $u = auth()->user();
        return $u->isOwner() || $u->isSuperAdmin() || $u->hasAnyPermission(['hr.manage', 'hr.attendance.view_all', 'staff.view']);
    }

    // ── Self service ────────────────────────────────────────────────────────

    /** GET /hr/attendance/me — today + this-month summary. */
    public function me(): JsonResponse
    {
        $companyId = $this->companyId();
        $userId = (int) auth()->id();
        $settings = HrSetting::forCompany($companyId);
        $profile = HrStaffProfile::forUser($companyId, $userId);

        $today = HrAttendance::where('company_id', $companyId)->where('user_id', $userId)
            ->whereDate('work_date', today())->with('breaks.breakType')->first();

        $monthRows = HrAttendance::where('company_id', $companyId)->where('user_id', $userId)
            ->whereBetween('work_date', [now()->startOfMonth(), now()->endOfMonth()])->get();

        return response()->json([
            'profile'  => $profile,
            'settings' => $settings->only(['office_start', 'office_end', 'geofence_radius_m', 'timezone', 'early_window_minutes', 'grace_minutes']),
            'schedule' => $this->svc->schedule($settings, $profile, now()),
            'today'    => $today,
            'open_break' => $today?->openBreak(),
            'break_types' => HrBreakType::where('company_id', $companyId)->where('is_active', true)->orderBy('sort_order')->get(),
            'month' => [
                'present_days'     => $monthRows->where('status', 'present')->count(),
                'worked_hours'     => round($monthRows->sum('worked_minutes') / 60, 1),
                'late_days'        => $monthRows->where('late_minutes', '>', 0)->count(),
                'overtime_hours'   => round($monthRows->where('overtime_status', 'approved')->sum('overtime_minutes') / 60, 1),
                'on_leave_days'    => $monthRows->where('status', 'on_leave')->count(),
            ],
        ]);
    }

    /** POST /hr/attendance/clock-in */
    public function clockIn(Request $request): JsonResponse
    {
        $data = $request->validate([
            'lat'    => 'nullable|numeric|between:-90,90',
            'lng'    => 'nullable|numeric|between:-180,180',
            'note'   => 'nullable|string|max:500',
            'source' => 'nullable|in:mobile,web,admin',
            'work_mode' => 'nullable|in:wfo,wfh',
        ]);

        $companyId = $this->companyId();
        $userId = (int) auth()->id();
        $settings = HrSetting::forCompany($companyId);
        $profile = HrStaffProfile::forUser($companyId, $userId);
        $now = now();

        $att = HrAttendance::firstOrNew([
            'company_id' => $companyId, 'user_id' => $userId, 'work_date' => $now->toDateString(),
        ]);
        if ($att->clock_in_at) {
            throw ValidationException::withMessages(['clock_in' => 'Already clocked in today.']);
        }

        $sched = $this->svc->schedule($settings, $profile, $now);
        $status = $this->svc->clockInStatus($now, $sched['start'], $settings->early_window_minutes, $settings->grace_minutes);
        $geo = $this->svc->geofence($settings, $profile, $data['lat'] ?? null, $data['lng'] ?? null);

        if ($status['requires_note'] && $settings->require_late_note && empty($data['note'])) {
            throw ValidationException::withMessages(['note' => 'A reason is required when clocking in late.']);
        }

        // note: geofence danger wins over the timing colour
        $noteColor = $geo['note_color'] ?? $status['color'];
        $noteMsg = $geo['note_message']
            ?? ($status['late_minutes'] > 0 ? "Clocked in {$status['late_minutes']} min late." : 'On time.');

        $att->fill([
            'clock_in_at'              => $now,
            'clock_in_lat'            => $data['lat'] ?? null,
            'clock_in_lng'            => $data['lng'] ?? null,
            'clock_in_distance_m'     => $geo['distance'],
            'clock_in_out_of_geofence' => $geo['out_of_geofence'],
            'clock_in_status'         => $status['color'],
            'work_mode'               => $data['work_mode'] ?? $profile->work_mode,
            'source'                  => $data['source'] ?? 'web',
            'late_minutes'            => $status['late_minutes'],
            'late_note'               => $data['note'] ?? null,
            'status'                  => 'present',
            'note_color'              => $noteColor,
            'note_message'            => $data['note'] ? ($noteMsg . ' — ' . $data['note']) : $noteMsg,
        ])->save();

        $this->svc->syncAvailability($settings, $companyId, $userId, 'working');

        return response()->json(['data' => $att->fresh()->load('breaks'), 'status' => $status, 'geofence' => $geo], 201);
    }

    /** POST /hr/attendance/clock-out */
    public function clockOut(Request $request): JsonResponse
    {
        $data = $request->validate([
            'lat'  => 'nullable|numeric|between:-90,90',
            'lng'  => 'nullable|numeric|between:-180,180',
            'note' => 'nullable|string|max:500',
        ]);

        $companyId = $this->companyId();
        $userId = (int) auth()->id();
        $settings = HrSetting::forCompany($companyId);
        $profile = HrStaffProfile::forUser($companyId, $userId);
        $now = now();

        $att = HrAttendance::where('company_id', $companyId)->where('user_id', $userId)
            ->whereDate('work_date', $now->toDateString())->first();
        if (!$att || !$att->clock_in_at) {
            throw ValidationException::withMessages(['clock_out' => 'Not clocked in today.']);
        }
        if ($att->clock_out_at) {
            throw ValidationException::withMessages(['clock_out' => 'Already clocked out today.']);
        }
        if ($att->openBreak()) {
            throw ValidationException::withMessages(['clock_out' => 'End your open break before clocking out.']);
        }

        $sched = $this->svc->schedule($settings, $profile, $now);
        $earlyLeave = $now->lt($sched['end']) ? (int) round($now->diffInSeconds($sched['end']) / 60) : 0;
        $overtime = $now->gt($sched['end']) ? (int) round($sched['end']->diffInSeconds($now) / 60) : 0;

        if ($earlyLeave > 0 && $settings->require_early_leave_note && empty($data['note'])) {
            throw ValidationException::withMessages(['note' => 'A reason is required when leaving before the scheduled end time.']);
        }

        $geo = $this->svc->geofence($settings, $profile, $data['lat'] ?? null, $data['lng'] ?? null);

        $att->fill([
            'clock_out_at'         => $now,
            'clock_out_lat'        => $data['lat'] ?? null,
            'clock_out_lng'        => $data['lng'] ?? null,
            'clock_out_distance_m' => $geo['distance'],
            'early_leave_minutes'  => $earlyLeave,
            'early_leave_note'     => $earlyLeave > 0 ? ($data['note'] ?? null) : null,
            'overtime_minutes'     => $overtime,
            'overtime_note'        => $overtime > 0 ? ($data['note'] ?? null) : null,
            'overtime_status'      => $overtime > 0 ? ($settings->overtime_needs_approval ? 'pending' : 'approved') : 'none',
        ])->save();

        $this->svc->recomputeTotals($att);
        $this->svc->syncAvailability($settings, $companyId, $userId, 'off');

        return response()->json(['data' => $att->fresh()->load('breaks')]);
    }

    // ── Breaks ─────────────────────────────────────────────────────────────

    public function breakStart(Request $request): JsonResponse
    {
        $data = $request->validate([
            'break_type_id' => 'nullable|integer',
            'lat' => 'nullable|numeric|between:-90,90',
            'lng' => 'nullable|numeric|between:-180,180',
        ]);

        $companyId = $this->companyId();
        $userId = (int) auth()->id();
        $settings = HrSetting::forCompany($companyId);
        $profile = HrStaffProfile::forUser($companyId, $userId);

        $att = HrAttendance::where('company_id', $companyId)->where('user_id', $userId)
            ->whereDate('work_date', today())->first();
        if (!$att || !$att->clock_in_at || $att->clock_out_at) {
            throw ValidationException::withMessages(['break' => 'You must be clocked in to start a break.']);
        }
        if ($att->openBreak()) {
            throw ValidationException::withMessages(['break' => 'A break is already running.']);
        }

        $type = $data['break_type_id']
            ? HrBreakType::where('company_id', $companyId)->find($data['break_type_id'])
            : null;

        if ($type && $type->daily_limit) {
            $used = $att->breaks()->where('break_type_id', $type->id)->count();
            if ($used >= $type->daily_limit) {
                throw ValidationException::withMessages(['break' => "Daily limit reached for \"{$type->name}\" ({$type->daily_limit}/day)."]);
            }
        }

        $geo = $this->svc->geofence($settings, $profile, $data['lat'] ?? null, $data['lng'] ?? null);

        $session = HrBreakSession::create([
            'company_id'      => $companyId,
            'user_id'         => $userId,
            'attendance_id'   => $att->id,
            'break_type_id'   => $type?->id,
            'start_at'        => now(),
            'start_lat'       => $data['lat'] ?? null,
            'start_lng'       => $data['lng'] ?? null,
            'start_distance_m' => $geo['distance'],
            'start_status'    => $geo['out_of_geofence'] ? 'danger' : 'info',
        ]);

        $this->svc->syncAvailability($settings, $companyId, $userId, 'on_break');

        return response()->json(['data' => $session->load('breakType')], 201);
    }

    public function breakEnd(Request $request): JsonResponse
    {
        $data = $request->validate([
            'lat'  => 'nullable|numeric|between:-90,90',
            'lng'  => 'nullable|numeric|between:-180,180',
            'note' => 'nullable|string|max:500',
        ]);

        $companyId = $this->companyId();
        $userId = (int) auth()->id();
        $settings = HrSetting::forCompany($companyId);
        $profile = HrStaffProfile::forUser($companyId, $userId);

        $att = HrAttendance::where('company_id', $companyId)->where('user_id', $userId)
            ->whereDate('work_date', today())->first();
        $session = $att?->openBreak();
        if (!$session) {
            throw ValidationException::withMessages(['break' => 'No running break.']);
        }

        $now = now();
        $minutes = max(0, (int) round($session->start_at->diffInSeconds($now) / 60));
        $type = $session->breakType;
        $overBy = ($type && $type->max_minutes && $minutes > $type->max_minutes) ? $minutes - $type->max_minutes : 0;

        $geo = $this->svc->geofence($settings, $profile, $data['lat'] ?? null, $data['lng'] ?? null);

        $session->update([
            'end_at'          => $now,
            'end_lat'         => $data['lat'] ?? null,
            'end_lng'         => $data['lng'] ?? null,
            'end_distance_m'  => $geo['distance'],
            'minutes'         => $minutes,
            'over_limit'      => $overBy > 0,
            'over_by_minutes' => $overBy,
            'note'            => $data['note'] ?? null,
        ]);

        $this->svc->recomputeTotals($att);
        $this->svc->syncAvailability($settings, $companyId, $userId, 'working');

        return response()->json([
            'data' => $session->fresh()->load('breakType'),
            'over_limit' => $overBy > 0,
            'over_by_minutes' => $overBy,
        ]);
    }

    // ── Team / admin ───────────────────────────────────────────────────────

    /** GET /hr/attendance — team attendance for a day/range. */
    public function index(Request $request): JsonResponse
    {
        abort_unless($this->canManage(), 403, 'No HR permission.');

        $rows = HrAttendance::where('company_id', $this->companyId())
            ->with('user:id,name,avatar,department', 'breaks:id,attendance_id,minutes,over_limit,break_type_id')
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->integer('user_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('date'), fn ($q) => $q->whereDate('work_date', $request->date('date')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('work_date', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('work_date', '<=', $request->date('to')))
            ->when(!$request->hasAny(['date', 'from', 'to']), fn ($q) => $q->whereDate('work_date', today()))
            ->orderByDesc('work_date')->orderByDesc('clock_in_at')
            ->paginate((int) min(max($request->integer('per_page', 100), 1), 1000));

        return response()->json($rows);
    }

    /** POST /hr/attendance — admin creates a missed / manual attendance row. */
    public function storeEntry(Request $request): JsonResponse
    {
        abort_unless($this->canManage(), 403, 'No HR permission.');

        $data = $request->validate([
            'user_id'      => 'required|integer',
            'work_date'    => 'required|date',
            'clock_in_at'  => 'nullable|date',
            'clock_out_at' => 'nullable|date',
            'status'       => 'nullable|in:present,absent,on_leave,half_day,weekly_off',
            'note_message' => 'nullable|string|max:255',
        ]);

        \App\Models\User::where('company_id', $this->companyId())->findOrFail($data['user_id']);

        $att = HrAttendance::firstOrNew([
            'company_id' => $this->companyId(),
            'user_id'    => $data['user_id'],
            'work_date'  => \Illuminate\Support\Carbon::parse($data['work_date'])->toDateString(),
        ]);
        $att->fill([
            'clock_in_at'  => $data['clock_in_at'] ?? $att->clock_in_at,
            'clock_out_at' => $data['clock_out_at'] ?? $att->clock_out_at,
            'status'       => $data['status'] ?? ($data['clock_in_at'] ?? $att->clock_in_at ? 'present' : ($att->status ?: 'absent')),
            'note_message' => $data['note_message'] ?? $att->note_message ?? 'Manual entry',
            'note_color'   => $att->note_color ?? 'info',
            'source'       => 'admin',
        ]);
        $att->save();
        $this->applyScheduleMetrics($att);

        return response()->json(['data' => $att->fresh()], 201);
    }

    /** PATCH /hr/attendance/{id} — admin correction of a wrong / missed entry. */
    public function update(Request $request, int $id): JsonResponse
    {
        abort_unless($this->canManage(), 403, 'No HR permission.');

        $att = HrAttendance::where('company_id', $this->companyId())->findOrFail($id);
        $data = $request->validate([
            'clock_in_at'  => 'nullable|date',
            'clock_out_at' => 'nullable|date',
            'status'       => 'nullable|in:present,absent,on_leave,half_day,weekly_off',
            'note_message' => 'nullable|string|max:255',
            'note_color'   => 'nullable|in:danger,warning,success,info,primary',
            'overtime_status' => 'nullable|in:none,pending,approved,rejected',
        ]);
        $att->update($data);
        $this->applyScheduleMetrics($att);

        return response()->json(['data' => $att->fresh()]);
    }

    /** Recompute late / early-leave / overtime / worked minutes against the schedule. */
    private function applyScheduleMetrics(HrAttendance $att): void
    {
        $settings = HrSetting::forCompany($att->company_id);
        $profile  = HrStaffProfile::forUser($att->company_id, $att->user_id);
        $date     = \Illuminate\Support\Carbon::parse($att->work_date);
        $sched    = $this->svc->schedule($settings, $profile, $date);

        if ($att->clock_in_at) {
            $st = $this->svc->clockInStatus($att->clock_in_at, $sched['start'], $settings->early_window_minutes, $settings->grace_minutes);
            $att->late_minutes = $st['late_minutes'];
            $att->clock_in_status = $st['color'];
        }
        if ($att->clock_out_at) {
            $att->early_leave_minutes = $att->clock_out_at->lt($sched['end']) ? (int) round($att->clock_out_at->diffInSeconds($sched['end']) / 60) : 0;
            $att->overtime_minutes = $att->clock_out_at->gt($sched['end']) ? (int) round($sched['end']->diffInSeconds($att->clock_out_at) / 60) : 0;
        }
        $att->save();
        $this->svc->recomputeTotals($att);
    }

    /** POST /hr/attendance/{id}/overtime — approve/reject the late-leaving / overtime. */
    public function reviewOvertime(Request $request, int $id): JsonResponse
    {
        $u = auth()->user();
        abort_unless(
            $u->isOwner() || $u->isSuperAdmin() || $u->hasAnyPermission(['hr.leave.approve', 'hr.manage']),
            403, 'No approval permission.',
        );

        $data = $request->validate(['decision' => 'required|in:approved,rejected', 'note' => 'nullable|string|max:255']);
        $att = HrAttendance::where('company_id', $this->companyId())->findOrFail($id);

        $att->update([
            'overtime_status'      => $data['decision'],
            'overtime_approved_by' => auth()->id(),
            'overtime_reviewed_at' => now(),
            'overtime_note'        => $data['note'] ? ($att->overtime_note . ' | ' . $data['note']) : $att->overtime_note,
        ]);

        return response()->json(['data' => $att->fresh()]);
    }

    /** GET /hr/attendance/payroll?month=YYYY-MM — hours + earnings per staff. */
    public function payroll(Request $request): JsonResponse
    {
        abort_unless($this->canManage(), 403, 'No HR permission.');

        $month = $request->filled('month')
            ? Carbon::createFromFormat('Y-m', $request->string('month'))->startOfMonth()
            : now()->startOfMonth();
        $end = $month->copy()->endOfMonth();
        $settings = HrSetting::forCompany($this->companyId());

        $profiles = HrStaffProfile::where('company_id', $this->companyId())->with('user:id,name,department')->get();

        $rows = $profiles->map(function (HrStaffProfile $p) use ($month, $end, $settings) {
            $att = HrAttendance::where('company_id', $p->company_id)->where('user_id', $p->user_id)
                ->whereBetween('work_date', [$month, $end])->get();

            $workedHours = round($att->sum('worked_minutes') / 60, 2);
            $otHours = round($att->where('overtime_status', 'approved')->sum('overtime_minutes') / 60, 2);
            $rate = $p->hourly_rate
                ?? ($p->monthly_salary ? round($p->monthly_salary / (26 * 8), 2) : null);

            $basePay = $rate !== null ? round($workedHours * $rate, 2) : null;
            $otPay = $rate !== null ? round($otHours * $rate * $settings->overtime_multiplier, 2) : null;

            return [
                'user'          => $p->user,
                'present_days'  => $att->where('status', 'present')->count(),
                'leave_days'    => $att->where('status', 'on_leave')->count(),
                'late_days'     => $att->where('late_minutes', '>', 0)->count(),
                'worked_hours'  => $workedHours,
                'overtime_hours' => $otHours,
                'hourly_rate'   => $rate,
                'base_pay'      => $basePay,
                'overtime_pay'  => $otPay,
                'total_pay'     => $basePay !== null ? round($basePay + ($otPay ?? 0), 2) : null,
            ];
        });

        return response()->json(['month' => $month->format('Y-m'), 'data' => $rows]);
    }
}
