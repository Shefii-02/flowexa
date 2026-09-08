<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Models\HrBreakType;
use App\Modules\Hr\Models\HrLeaveType;
use App\Modules\Hr\Models\HrSetting;
use App\Modules\Hr\Models\HrStaffProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HrConfigController extends Controller
{
    private function companyId(): int
    {
        return (int) auth()->user()->company_id;
    }

    private function guard(): void
    {
        $u = auth()->user();
        abort_unless(
            $u->isOwner() || $u->isSuperAdmin() || $u->hasAnyPermission(['hr.manage', 'staff.manage']),
            403, 'No HR management permission.',
        );
    }

    // ── Settings ───────────────────────────────────────────────────────────

    public function showSettings(): JsonResponse
    {
        return response()->json(['data' => HrSetting::forCompany($this->companyId())]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $this->guard();
        $data = $request->validate([
            'office_lat'              => 'nullable|numeric|between:-90,90',
            'office_lng'              => 'nullable|numeric|between:-180,180',
            'geofence_radius_m'       => 'nullable|integer|min:20|max:5000',
            'office_start'            => 'nullable|date_format:H:i',
            'office_end'              => 'nullable|date_format:H:i',
            'early_window_minutes'    => 'nullable|integer|min:0|max:120',
            'grace_minutes'           => 'nullable|integer|min:0|max:120',
            'require_late_note'       => 'boolean',
            'require_early_leave_note' => 'boolean',
            'overtime_needs_approval' => 'boolean',
            'overtime_multiplier'     => 'nullable|numeric|min:1|max:5',
            'auto_availability'       => 'boolean',
            'leave_auto_approve'      => 'boolean',
            'timezone'                => 'nullable|string|max:64',
        ]);

        $settings = HrSetting::forCompany($this->companyId());
        $settings->update($data);

        return response()->json(['data' => $settings]);
    }

    // ── Break types ───────────────────────────────────────────────────────

    public function breakTypes(): JsonResponse
    {
        return response()->json(['data' => HrBreakType::where('company_id', $this->companyId())
            ->orderBy('sort_order')->orderBy('name')->get()]);
    }

    public function storeBreakType(Request $request): JsonResponse
    {
        $this->guard();
        $data = $this->validateBreakType($request);
        $data['company_id'] = $this->companyId();

        return response()->json(['data' => HrBreakType::create($data)], 201);
    }

    public function updateBreakType(Request $request, int $id): JsonResponse
    {
        $this->guard();
        $bt = HrBreakType::where('company_id', $this->companyId())->findOrFail($id);
        $bt->update($this->validateBreakType($request, true));

        return response()->json(['data' => $bt->fresh()]);
    }

    public function destroyBreakType(int $id): JsonResponse
    {
        $this->guard();
        HrBreakType::where('company_id', $this->companyId())->findOrFail($id)->delete();

        return response()->json(['message' => 'Deleted.']);
    }

    private function validateBreakType(Request $request, bool $isUpdate = false): array
    {
        $req = $isUpdate ? 'sometimes' : 'required';

        return $request->validate([
            'name'         => [$req, 'string', 'max:60'],
            'max_minutes'  => ['nullable', 'integer', 'min:1', 'max:480'],
            'daily_limit'  => ['nullable', 'integer', 'min:1', 'max:20'],
            'is_paid'      => ['boolean'],
            'requires_gps' => ['boolean'],
            'sort_order'   => ['integer'],
            'is_active'    => ['boolean'],
        ]);
    }

    // ── Leave types ───────────────────────────────────────────────────────

    public function leaveTypes(): JsonResponse
    {
        return response()->json(['data' => HrLeaveType::where('company_id', $this->companyId())
            ->orderBy('sort_order')->orderBy('name')->get()]);
    }

    public function storeLeaveType(Request $request): JsonResponse
    {
        $this->guard();
        $data = $this->validateLeaveType($request);
        $data['company_id'] = $this->companyId();

        return response()->json(['data' => HrLeaveType::create($data)], 201);
    }

    public function updateLeaveType(Request $request, int $id): JsonResponse
    {
        $this->guard();
        $lt = HrLeaveType::where('company_id', $this->companyId())->findOrFail($id);
        $lt->update($this->validateLeaveType($request, true));

        return response()->json(['data' => $lt->fresh()]);
    }

    public function destroyLeaveType(int $id): JsonResponse
    {
        $this->guard();
        HrLeaveType::where('company_id', $this->companyId())->findOrFail($id)->delete();

        return response()->json(['message' => 'Deleted.']);
    }

    private function validateLeaveType(Request $request, bool $isUpdate = false): array
    {
        $req = $isUpdate ? 'sometimes' : 'required';

        return $request->validate([
            'name'              => [$req, 'string', 'max:60'],
            'is_paid'           => ['boolean'],
            'max_days_per_year' => ['nullable', 'integer', 'min:1', 'max:365'],
            'requires_approval' => ['boolean'],
            'color'             => ['nullable', 'in:danger,warning,success,info,primary'],
            'sort_order'        => ['integer'],
            'is_active'         => ['boolean'],
        ]);
    }

    // ── Per-staff attendance profiles ─────────────────────────────────────

    public function staffProfiles(): JsonResponse
    {
        $this->guard();

        $profiles = HrStaffProfile::where('company_id', $this->companyId())
            ->with('user:id,name,email,department,avatar,is_active')->get()->keyBy('user_id');

        // include staff who don't have a profile row yet
        $staff = \App\Models\User::where('company_id', $this->companyId())
            ->get(['id', 'name', 'email', 'department', 'avatar', 'is_active']);

        $rows = $staff->map(function ($u) use ($profiles) {
            $p = $profiles->get($u->id);
            return [
                'user'            => $u,
                'attendance_type' => $p?->attendance_type ?? 'manual',
                'work_mode'       => $p?->work_mode ?? 'wfo',
                'duty_start'      => $p?->duty_start,
                'duty_end'        => $p?->duty_end,
                'weekly_off'      => $p?->weekly_off ?? [],
                'monthly_salary'  => $p?->monthly_salary,
                'hourly_rate'     => $p?->hourly_rate,
                'is_active'       => $p?->is_active ?? true,
            ];
        });

        return response()->json(['data' => $rows]);
    }

    public function updateStaffProfile(Request $request, int $userId): JsonResponse
    {
        $this->guard();

        $data = $request->validate([
            'attendance_type' => ['nullable', 'in:gps,manual'],
            'work_mode'       => ['nullable', 'in:wfo,wfh,hybrid'],
            'duty_start'      => ['nullable', 'date_format:H:i'],
            'duty_end'        => ['nullable', 'date_format:H:i'],
            'weekly_off'      => ['nullable', 'array'],
            'weekly_off.*'    => ['integer', 'between:0,6'],
            'monthly_salary'  => ['nullable', 'numeric', 'min:0'],
            'hourly_rate'     => ['nullable', 'numeric', 'min:0'],
            'is_active'       => ['boolean'],
        ]);

        \App\Models\User::where('company_id', $this->companyId())->findOrFail($userId);

        $profile = HrStaffProfile::forUser($this->companyId(), $userId);
        $profile->update($data);

        return response()->json(['data' => $profile->fresh()]);
    }
}
