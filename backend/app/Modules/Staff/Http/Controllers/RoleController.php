<?php

namespace App\Modules\Staff\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Modules\Staff\Http\Resources\RoleResource;
use App\Modules\Staff\Services\StaffService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RoleController extends Controller
{
    public function __construct(
        private readonly StaffService $staffService,
    ) {}

    // ─── GET /roles ───────────────────────────────────────────────────────────
    public function index(): JsonResponse
    {
        $companyId = auth()->user()->company_id;
        $roles = $this->staffService->roles($companyId);

        return response()->json([
            'roles' => RoleResource::collection($roles),
        ]);
    }

    // ─── GET /roles/{role} ────────────────────────────────────────────────────
    public function show(int $role): JsonResponse
    {
        $companyId = auth()->user()->company_id;
        $roles  = $this->staffService->roles($companyId);
        $found  = $roles->firstWhere('id', $role);

        if (!$found) {
            return response()->json(['message' => 'Role not found.'], 404);
        }

        return response()->json([
            'role' => new RoleResource($found),
        ]);
    }

    // ─── GET /roles/permissions ───────────────────────────────────────────────
    // Returns ALL permissions grouped by section — used by the role editor UI.
    // Also auto-syncs any newly added permissions to the admin role so admins
    // never lose access to a new feature after a seeder run.
    public function allPermissions(): JsonResponse
    {
        $companyId = auth()->user()->company_id;
        if ($companyId) {
            $this->autoSyncAdminRole($companyId);
        }

        $grouped = Permission::orderBy('sort_order')
            ->get()
            ->groupBy('group')
            ->map(fn($perms, $group) => [
                'group'       => $group,
                'permissions' => $perms->map(fn($p) => [
                    'id'    => $p->id,
                    'key'   => $p->key,
                    'label' => $p->label,
                    'type'  => $p->type,
                ])->values(),
            ])
            ->values();

        return response()->json(['permissions' => $grouped]);
    }

    // ─── POST /roles/{role}/reset-permissions ─────────────────────────────────
    public function resetToDefaults(int $role): JsonResponse
    {
        $companyId = auth()->user()->company_id;

        $record = Role::where(function ($q) use ($companyId) {
            $q->whereNull('company_id')->orWhere('company_id', $companyId);
        })->find($role);

        if (!$record) {
            return response()->json(['message' => 'Role not found.'], 404);
        }

        $allPerms   = Permission::all();
        $permMap    = $allPerms->pluck('id', 'key');
        $defaultKeys = $this->defaultPermissionsForRole($record->name, $allPerms->pluck('key')->toArray());

        $validKeys = array_values(array_unique(array_filter($defaultKeys, fn($k) => $permMap->has($k))));
        $permIds   = array_values($permMap->only($validKeys)->toArray());

        DB::transaction(function () use ($record, $validKeys, $permIds) {
            $record->permissionRelations()->sync($permIds);
            $record->update(['permissions' => $validKeys]);
        });

        return response()->json([
            'message' => 'Permissions reset to defaults.',
            'role'    => new RoleResource($record->fresh()->loadCount('users')),
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** Compute the canonical permission key list for a named system role. */
    private function defaultPermissionsForRole(string $roleName, array $allKeys): array
    {
        $allViewer = array_values(array_filter($allKeys, fn($k) => str_ends_with($k, '.view')));
        $allManage = array_values(array_filter($allKeys, fn($k) => str_ends_with($k, '.manage')));

        return match ($roleName) {
            'superadmin', 'owner' => array_merge($allViewer, $allManage),

            'admin' => array_values(array_filter(
                array_merge($allViewer, $allManage),
                fn($k) => !in_array($k, ['plans.manage', 'roles.manage'])
            )),

            'team_lead' => array_values(array_unique([
                ...$allViewer,
                'contacts.manage', 'labels.manage', 'campaigns.manage',
                'flow_builder.manage', 'inbox.manage', 'leads.manage',
                'surveys.manage', 'templates.manage',
                'wa_chat.sessions.manage', 'wa_chat.chats.manage',
                'wa_chat.message_sender.manage',
                'wa_agent.automations.manage', 'wa_agent.leads.manage',
                'staff.manage',
            ])),

            'counsellor' => [
                'dashboard.view', 'contacts.view', 'contacts.manage',
                'leads.view', 'leads.manage',
                'inbox.view', 'inbox.manage',
                'wa_chat.chats.view', 'wa_chat.chats.manage',
                'wa_chat.message_sender.view', 'wa_chat.message_sender.manage',
                'message_logs.view', 'templates.view',
                'wa_agent.leads.view',
            ],

            'viewer' => $allViewer,

            default => [], // custom roles have no predefined defaults
        };
    }

    /** Silently sync any new permissions that belong to the admin default set. */
    private function autoSyncAdminRole(int $companyId): void
    {
        $adminRole = Role::where(function ($q) use ($companyId) {
            $q->whereNull('company_id')->orWhere('company_id', $companyId);
        })->where('name', 'admin')->first();

        if (!$adminRole) return;

        $allPerms    = Permission::all();
        $permMap     = $allPerms->pluck('id', 'key');
        $defaultKeys = $this->defaultPermissionsForRole('admin', $allPerms->pluck('key')->toArray());
        $currentKeys = $adminRole->permissions ?? [];
        $missingKeys = array_diff($defaultKeys, $currentKeys);

        if (empty($missingKeys)) return;

        $newKeys = array_values(array_unique(array_merge($currentKeys, $missingKeys)));
        $permIds = array_values($permMap->only($newKeys)->toArray());

        DB::transaction(function () use ($adminRole, $newKeys, $permIds) {
            $adminRole->permissionRelations()->sync($permIds);
            $adminRole->update(['permissions' => $newKeys]);
        });
    }

    // ─── POST /roles ──────────────────────────────────────────────────────────
    public function store(Request $request): JsonResponse
    {
        $user      = auth()->user();
        $companyId = $user->company_id;

        $data = $request->validate([
            'name'           => 'required|string|max:80',
            'description'    => 'nullable|string|max:255',
            'color'          => 'nullable|string|max:7',
            'permission_ids' => 'nullable|array',
            'permission_ids.*' => 'integer|exists:permissions,id',
        ]);

        // Prevent name collision with system roles
        $systemNames = Role::where('is_system', true)->pluck('name')->map(fn($n) => strtolower($n))->toArray();
        if (in_array(strtolower($data['name']), $systemNames)) {
            return response()->json(['message' => 'Cannot use a system role name.'], 422);
        }

        // Prevent duplicate name within company
        if (Role::where('company_id', $companyId)->where('name', $data['name'])->exists()) {
            return response()->json(['message' => 'A role with this name already exists.'], 422);
        }

        $permIds = $data['permission_ids'] ?? [];
        $permKeys = Permission::whereIn('id', $permIds)->pluck('key')->toArray();

        $role = DB::transaction(function () use ($data, $companyId, $permIds, $permKeys) {
            $role = Role::create([
                'company_id'  => $companyId,
                'name'        => $data['name'],
                'label'       => $data['name'],
                'description' => $data['description'] ?? null,
                'color'       => $data['color'] ?? '#6366f1',
                'permissions' => $permKeys,
                'is_system'   => false,
                'is_active'   => true,
            ]);

            if (!empty($permIds)) {
                $role->permissionRelations()->sync($permIds);
            }

            return $role->load('users');
        });

        return response()->json([
            'message' => 'Role created.',
            'role'    => new RoleResource($role->loadCount('users')),
        ], 201);
    }

    // ─── PUT /roles/{role} ────────────────────────────────────────────────────
    public function update(Request $request, int $role): JsonResponse
    {
        $user      = auth()->user();
        $companyId = $user->company_id;

        $record = Role::where(function ($q) use ($companyId) {
            $q->whereNull('company_id')->orWhere('company_id', $companyId);
        })->find($role);

        if (!$record) {
            return response()->json(['message' => 'Role not found.'], 404);
        }

        if ($record->is_system) {
            // System roles: only description and color can change
            $data = $request->validate([
                'description' => 'nullable|string|max:255',
                'color'       => 'nullable|string|max:7',
            ]);
            $record->update($data);
            return response()->json(['message' => 'Role updated.', 'role' => new RoleResource($record->loadCount('users'))]);
        }

        $data = $request->validate([
            'name'           => 'sometimes|required|string|max:80',
            'description'    => 'nullable|string|max:255',
            'color'          => 'nullable|string|max:7',
            'is_active'      => 'sometimes|boolean',
            'permission_ids' => 'nullable|array',
            'permission_ids.*' => 'integer|exists:permissions,id',
        ]);

        // Duplicate name guard
        if (isset($data['name']) && $data['name'] !== $record->name) {
            if (Role::where('company_id', $companyId)->where('name', $data['name'])->where('id', '!=', $role)->exists()) {
                return response()->json(['message' => 'A role with this name already exists.'], 422);
            }
        }

        DB::transaction(function () use ($record, $data) {
            $permIds = $data['permission_ids'] ?? null;
            unset($data['permission_ids']);

            if (isset($data['name'])) {
                $data['label'] = $data['name'];
            }

            if ($permIds !== null) {
                $permKeys = Permission::whereIn('id', $permIds)->pluck('key')->toArray();
                $data['permissions'] = $permKeys;
                $record->permissionRelations()->sync($permIds);
            }

            $record->update($data);
        });

        return response()->json([
            'message' => 'Role updated.',
            'role'    => new RoleResource($record->fresh()->loadCount('users')),
        ]);
    }

    // ─── DELETE /roles/{role} ─────────────────────────────────────────────────
    public function destroy(int $role): JsonResponse
    {
        $companyId = auth()->user()->company_id;

        $record = Role::where('company_id', $companyId)->find($role);

        if (!$record) {
            return response()->json(['message' => 'Role not found.'], 404);
        }

        if ($record->is_system) {
            return response()->json(['message' => 'System roles cannot be deleted.'], 403);
        }

        $userCount = $record->users()->count();
        if ($userCount > 0) {
            return response()->json([
                'message' => "Cannot delete role — reassign {$userCount} user(s) first.",
            ], 422);
        }

        $record->permissionRelations()->detach();
        $record->delete();

        return response()->json(['message' => 'Role deleted.']);
    }
}
