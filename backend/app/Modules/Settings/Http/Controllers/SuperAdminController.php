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


    public function permissions(): \Illuminate\Http\JsonResponse
    {
        $roles = \App\Models\Role::all()->map(fn($r) => [
            'id'          => $r->id,
            'name'        => $r->name,
            'label'       => $r->label,
            'is_system'   => $r->is_system,
            'permissions' => $r->permissions,
        ]);

        $allPermissions = [
            'contacts'   => ['contacts.view', 'contacts.create', 'contacts.edit', 'contacts.delete', 'contacts.import'],
            'labels'     => ['labels.view', 'labels.manage'],
            'staff'      => ['staff.view', 'staff.create', 'staff.edit', 'staff.delete'],
            'flow'       => ['flow.view', 'flow.manage'],
            'campaigns'  => ['campaigns.view', 'campaigns.create', 'campaigns.edit', 'campaigns.delete', 'campaigns.launch'],
            'leads'      => ['leads.view_own', 'leads.view_all', 'leads.create', 'leads.edit', 'leads.assign', 'leads.delete'],
            'analytics'  => ['analytics.view_own', 'analytics.view_all'],
            'billing'    => ['billing.view', 'billing.manage'],
            'settings'   => ['settings.manage'],
            'crm'        => ['crm.sync'],
        ];

        return response()->json(['roles' => $roles, 'all_permissions' => $allPermissions]);
    }

    public function updatePermissions(\Illuminate\Http\Request $request, int $roleId): \Illuminate\Http\JsonResponse
    {
        $request->validate(['permissions' => ['required', 'array']]);
        $role = \App\Models\Role::findOrFail($roleId);

        // System roles (superadmin, owner) cannot have permissions reduced
        if (in_array($role->name, ['superadmin', 'owner'])) {
            return response()->json(['message' => 'Cannot modify superadmin or owner permissions.'], 403);
        }

        $role->update(['permissions' => $request->permissions]);
        return response()->json(['message' => 'Permissions updated.', 'role' => $role->fresh()]);
    }
}
