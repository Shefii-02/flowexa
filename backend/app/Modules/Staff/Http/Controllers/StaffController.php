<?php

namespace App\Modules\Staff\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Staff\DTOs\CreateStaffDTO;
use App\Modules\Staff\DTOs\ResetPasswordDTO;
use App\Modules\Staff\DTOs\StaffFilterDTO;
use App\Modules\Staff\DTOs\UpdateStaffDTO;
use App\Modules\Staff\Http\Requests\CreateStaffRequest;
use App\Modules\Staff\Http\Requests\ResetPasswordRequest;
use App\Modules\Staff\Http\Requests\StaffFilterRequest;
use App\Modules\Staff\Http\Requests\UpdateStaffRequest;
use App\Modules\Staff\Http\Resources\RoleResource;
use App\Modules\Staff\Http\Resources\StaffCollection;
use App\Modules\Staff\Http\Resources\StaffPerformanceResource;
use App\Modules\Staff\Http\Resources\StaffResource;
use App\Modules\Staff\Services\StaffService;
use Illuminate\Http\JsonResponse;

class StaffController extends Controller
{
    public function __construct(
        private readonly StaffService $staffService,
    ) {}

    // ─── GET /staff ───────────────────────────────────────────────────────────
    public function index(StaffFilterRequest $request): JsonResponse
    {
        $paginator = $this->staffService->list(
            companyId: auth()->user()->company_id,
            filter:    StaffFilterDTO::fromRequest($request->validated()),
        );

        return (new StaffCollection($paginator))->response();
    }

    // ─── GET /staff/{id} ──────────────────────────────────────────────────────
    public function show(int $staff): JsonResponse
    {
        $user = $this->staffService->show(
            staffId:   $staff,
            companyId: auth()->user()->company_id,
        );

        return response()->json([
            'staff' => new StaffResource($user),
        ]);
    }

    // ─── POST /staff ──────────────────────────────────────────────────────────
    public function store(CreateStaffRequest $request): JsonResponse
    {
        $user = $this->staffService->create(
            companyId: auth()->user()->company_id,
            dto:       CreateStaffDTO::fromRequest($request->validated()),
        );

        return response()->json([
            'message' => 'Staff member created successfully.',
            'staff'   => new StaffResource($user),
        ], 201);
    }

    // ─── PUT /staff/{id} ──────────────────────────────────────────────────────
    public function update(UpdateStaffRequest $request, int $staff): JsonResponse
    {
        $user = $this->staffService->update(
            staffId:   $staff,
            companyId: auth()->user()->company_id,
            dto:       UpdateStaffDTO::fromRequest($request->validated()),
        );

        return response()->json([
            'message' => 'Staff member updated.',
            'staff'   => new StaffResource($user),
        ]);
    }

    // ─── PATCH /staff/{id}/toggle-active ─────────────────────────────────────
    public function toggleActive(int $staff): JsonResponse
    {
        $user = $this->staffService->toggleActive(
            staffId:   $staff,
            companyId: auth()->user()->company_id,
        );

        return response()->json([
            'message'   => $user->is_active ? 'Staff activated.' : 'Staff deactivated.',
            'is_active' => $user->is_active,
            'staff'     => new StaffResource($user),
        ]);
    }

    // ─── PATCH /staff/{id}/reset-password ────────────────────────────────────
    public function resetPassword(ResetPasswordRequest $request, int $staff): JsonResponse
    {
        $this->staffService->resetPassword(
            staffId:   $staff,
            companyId: auth()->user()->company_id,
            dto:       ResetPasswordDTO::fromRequest($request->validated()),
        );

        return response()->json([
            'message' => 'Password reset successfully.',
        ]);
    }

    // ─── DELETE /staff/{id} ───────────────────────────────────────────────────
    public function destroy(int $staff): JsonResponse
    {
        $this->staffService->delete(
            staffId:   $staff,
            companyId: auth()->user()->company_id,
        );

        return response()->json([
            'message' => 'Staff member removed. Their leads have been unassigned.',
        ]);
    }

    // ─── GET /staff/performance ───────────────────────────────────────────────
    public function performance(): JsonResponse
    {
        $data = $this->staffService->performance(auth()->user()->company_id);

        return response()->json([
            'performance' => StaffPerformanceResource::collection($data),
        ]);
    }

    // ─── GET /staff/departments ───────────────────────────────────────────────
    public function departments(): JsonResponse
    {
        $departments = $this->staffService->departments(auth()->user()->company_id);

        return response()->json([
            'departments' => $departments->values(),
        ]);
    }
}

