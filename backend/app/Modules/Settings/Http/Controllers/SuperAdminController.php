<?php

namespace App\Modules\Settings\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\MessageLog;
use App\Models\Plan;
use App\Modules\Settings\DTOs\MessageLogFilterDTO;
use App\Modules\Settings\DTOs\SuperAdminCreateCompanyDTO;
use App\Modules\Settings\DTOs\TopUpDTO;
use App\Modules\Settings\DTOs\UpdateCompanyStatusDTO;
use App\Modules\Settings\DTOs\UpdateSettingsDTO;
use App\Modules\Settings\DTOs\WaCredentialsDTO;
use App\Modules\Settings\Http\Requests\SuperAdminCreateCompanyRequest;
use App\Modules\Settings\Http\Requests\TopUpRequest;
use App\Modules\Settings\Http\Requests\UpdateCompanyStatusRequest;
use App\Modules\Settings\Http\Requests\UpdateSettingsRequest;
use App\Modules\Settings\Http\Requests\WaCredentialsRequest;
use App\Modules\Settings\Http\Resources\SuperAdminCompanyResource;
use App\Modules\Settings\Services\SettingsService;
use App\Modules\Settings\Services\SuperAdminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// ─── SuperAdmin Controller ────────────────────────────────────────────────────
class SuperAdminController extends Controller
{
    public function __construct(private readonly SuperAdminService $superAdminService) {}

    public function dashboard(): JsonResponse
    {
        return response()->json($this->superAdminService->dashboard());
    }

    public function stats(): JsonResponse
    {
        return response()->json($this->superAdminService->stats());
    }

    public function companies(Request $request): JsonResponse
    {
        $companies = $this->superAdminService->companies(
            $request->all()
        );

        return response()->json(
            SuperAdminCompanyResource::collection($companies)
        );
    }

    public function showCompany(Company $company): JsonResponse
    {
        return response()->json(['company' => $company->load(['plan', 'wallet', 'users.role'])]);
    }

    public function createCompany(SuperAdminCreateCompanyRequest $request): JsonResponse
    {
        $company = $this->superAdminService->createCompany(
            SuperAdminCreateCompanyDTO::fromRequest($request->validated())
        );
        return response()->json(['message' => 'Company created.', 'company' => $company], 201);
    }

    public function updateCompany(Request $request, Company $company): JsonResponse
    {
        $request->validate([
            'name'    => ['sometimes', 'string', 'max:100'],
            'plan_id' => ['sometimes', 'integer', 'exists:plans,id'],
            'email'   => ['sometimes', 'email'],
            'max_devices_per_user' => ['sometimes', 'integer', 'min:1', 'max:20'],
        ]);
        $c = $this->superAdminService->updateCompany($company, $request->all());
        return response()->json(['message' => 'Company updated.', 'company' => $c]);
    }

    public function updateStatus(UpdateCompanyStatusRequest $request, Company $company): JsonResponse
    {
        $c = $this->superAdminService->updateStatus($company, UpdateCompanyStatusDTO::fromRequest($request->validated()));
        return response()->json(['message' => "Company status set to {$c->status}.", 'company' => $c]);
    }

    public function deleteCompany(Company $company): JsonResponse
    {
        $this->superAdminService->deleteCompany($company);
        return response()->json(['message' => 'Company deleted.']);
    }

    public function topUp(TopUpRequest $request, Company $company): JsonResponse
    {
        $result = $this->superAdminService->topUp($company, TopUpDTO::fromRequest($request->validated()));
        return response()->json(['message' => "Credited {$result['credited']} messages.", 'balance' => $result['balance']]);
    }

    public function impersonate(Company $company): JsonResponse
    {
        $token = $this->superAdminService->impersonate($company);
        return response()->json([
            'message'      => "Impersonating {$company->name}.",
            'access_token' => $token,
            'token_type'   => 'bearer',
        ]);
    }

    public function plans(): JsonResponse
    {
        return response()->json(['plans' => $this->superAdminService->plans()]);
    }

    public function createPlan(Request $request): JsonResponse
    {
        $request->validate([
            'name'           => ['required', 'string', 'max:50'],
            'messages_limit' => ['required', 'integer', 'min:1'],
            'price'          => ['required', 'numeric', 'min:0'],
            'features'       => ['nullable', 'array'],
            'is_active'      => ['nullable', 'boolean'],
        ]);
        $plan = $this->superAdminService->createPlan($request->all());
        return response()->json(['message' => 'Plan created.', 'plan' => $plan], 201);
    }

    public function updatePlan(Request $request, Plan $plan): JsonResponse
    {
        $request->validate([
            'name'           => ['sometimes', 'string', 'max:50'],
            'messages_limit' => ['sometimes', 'integer', 'min:10'],
            'price'          => ['sometimes', 'numeric', 'min:0'],
            'features'       => ['nullable', 'array'],
            'is_active'      => ['nullable', 'boolean'],
        ]);
        $p = $this->superAdminService->updatePlan($plan, $request->all());
        return response()->json(['message' => 'Plan updated.', 'plan' => $p]);
    }

    public function users(Request $request): JsonResponse
    {
        return response()->json($this->superAdminService->users($request->all()));
    }

    public function exitImpersonation(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        // The superadmin's original token is passed as X-Original-Token header
        // set by frontend when impersonating
        $originalToken = $request->header('X-Original-Token');
        if (!$originalToken) {
            return response()->json(['message' => 'No original token found.'], 422);
        }
        return response()->json([
            'message'      => 'Returned to superadmin account.',
            'access_token' => $originalToken,
        ]);
    }


    /** The full permission catalogue from the DB, grouped. */
    private function permissionCatalogue(): array
    {
        return \App\Models\Permission::orderBy('sort_order')->orderBy('key')->get()
            ->groupBy('group')
            ->map(fn ($g) => $g->map(fn ($p) => [
                'key'   => $p->key,
                'label' => $p->label,
                'type'  => $p->type,
            ])->values())
            ->toArray();
    }

    /** Legacy global editor — every role across every company. */
    public function permissions(): \Illuminate\Http\JsonResponse
    {
        $roles = \App\Models\Role::with('company:id,name')->orderBy('company_id')->get()->map(fn ($r) => [
            'id'          => $r->id,
            'name'        => $r->name,
            'label'       => $r->label,
            'company'     => $r->company?->name,
            'company_id'  => $r->company_id,
            'is_system'   => $r->is_system,
            'permissions' => $r->permissions ?? [],
        ]);

        return response()->json([
            'roles'           => $roles,
            'catalogue'       => $this->permissionCatalogue(),
            'all_permissions' => \App\Models\Permission::pluck('key')->all(),
        ]);
    }

    /** GET /superadmin/companies/{company}/permissions — company-scoped editor. */
    public function companyPermissions(Company $company): \Illuminate\Http\JsonResponse
    {
        $roles = \App\Models\Role::where('company_id', $company->id)
            ->orderBy('sort_order')->orderBy('id')->get()->map(fn ($r) => [
                'id'          => $r->id,
                'name'        => $r->name,
                'label'       => $r->label,
                'is_system'   => $r->is_system,
                'protected'   => in_array($r->name, ['superadmin', 'owner'], true),
                'permissions' => $r->permissions ?? [],
                'user_count'  => $r->users()->count(),
            ]);

        return response()->json([
            'company'   => ['id' => $company->id, 'name' => $company->name],
            'catalogue' => $this->permissionCatalogue(),
            'roles'     => $roles,
        ]);
    }

    /** PUT /superadmin/companies/{company}/roles/{role}/permissions */
    public function updateCompanyRolePermissions(\Illuminate\Http\Request $request, Company $company, int $roleId): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate(['permissions' => ['present', 'array'], 'permissions.*' => ['string']]);

        $role = \App\Models\Role::where('company_id', $company->id)->findOrFail($roleId);
        if (in_array($role->name, ['superadmin', 'owner'], true)) {
            return response()->json(['message' => 'The superadmin and owner roles always have full access.'], 422);
        }

        $ids = \App\Models\Permission::whereIn('key', $data['permissions'])->pluck('id')->all();
        $role->syncPermissions($ids); // updates both the pivot and the JSON column

        return response()->json(['message' => 'Permissions updated.', 'role' => $role->fresh()]);
    }

    /** POST /superadmin/companies/{company}/permissions/resync — rebuild default system roles for the company. */
    public function resyncCompanyPermissions(Company $company): \Illuminate\Http\JsonResponse
    {
        $permMap = \App\Models\Permission::pluck('id', 'key');
        $roleDefs = (new \Database\Seeders\PermissionsSeeder())->rolePermissions($permMap->keys()->all());

        foreach (\App\Models\Role::where('company_id', $company->id)->where('is_system', true)->get() as $role) {
            $keys = $roleDefs[$role->name] ?? null;
            if ($keys === null) {
                continue;
            }
            $valid = array_values(array_unique(array_filter($keys, fn ($k) => $permMap->has($k))));
            $role->syncPermissions(array_values($permMap->only($valid)->all()));
        }

        return response()->json(['message' => 'Default role permissions re-applied for this company.']);
    }

    public function updatePermissions(\Illuminate\Http\Request $request, int $roleId): \Illuminate\Http\JsonResponse
    {
        $request->validate(['permissions' => ['required', 'array']]);
        $role = \App\Models\Role::findOrFail($roleId);

        if (in_array($role->name, ['superadmin', 'owner'], true)) {
            return response()->json(['message' => 'Cannot modify superadmin or owner permissions.'], 403);
        }

        $ids = \App\Models\Permission::whereIn('key', $request->permissions)->pluck('id')->all();
        $role->syncPermissions($ids);

        return response()->json(['message' => 'Permissions updated.', 'role' => $role->fresh()]);
    }
}
