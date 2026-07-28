<?php
namespace App\Modules\Staff\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Staff\Http\Resources\RoleResource;
use App\Modules\Staff\Services\StaffService;
use Illuminate\Http\JsonResponse;

class RoleController extends Controller
{
    public function __construct(
        private readonly StaffService $staffService,
    ) {}

    // ─── GET /roles ───────────────────────────────────────────────────────────
    public function index(): JsonResponse
    {
        $roles = $this->staffService->roles();

        return response()->json([
            'roles' => RoleResource::collection($roles),
        ]);
    }

    // ─── GET /roles/{role} ────────────────────────────────────────────────────
    public function show(int $role): JsonResponse
    {
        $roles = $this->staffService->roles();
        $found = $roles->firstWhere('id', $role);

        if (!$found) {
            return response()->json(['message' => 'Role not found.'], 404);
        }

        return response()->json([
            'role' => new RoleResource($found),
        ]);
    }
}
