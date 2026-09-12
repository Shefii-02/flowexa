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
use App\Modules\Settings\Http\Resources\SuperAdminCompanyConfigResource;
use App\Modules\Settings\Http\Resources\SuperAdminCompanyResource;
use App\Modules\Settings\Services\SettingsService;
use App\Modules\Settings\Services\SuperAdminService;
use App\Modules\WaChat\Services\WaChatTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// ─── SuperAdmin Controller ────────────────────────────────────────────────────
class SuperAdminController extends Controller
{
    /** System roles that always keep full access and cannot be edited. */
    private const PROTECTED_ROLE_NAMES = ['superadmin', 'owner'];

    public function __construct(private readonly SuperAdminService $superAdminService) {}

    public function dashboard(): JsonResponse
    {
        return response()->json($this->superAdminService->dashboard());
    }

    public function stats(): JsonResponse
    {
        return response()->json($this->superAdminService->stats());
    }

    public function billing(): JsonResponse
    {
        return response()->json($this->superAdminService->billing());
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
            'name'          => ['sometimes', 'string', 'max:100'],
            'plan_id'       => ['sometimes', 'integer', 'exists:plans,id'],
            'email'         => ['sometimes', 'email'],
            'company_email' => ['sometimes', 'email'],
            'company_phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'website'       => ['sometimes', 'nullable', 'string', 'max:150'],
            'max_devices_per_user' => ['sometimes', 'integer', 'min:1', 'max:20'],
        ]);
        $c = $this->superAdminService->updateCompany($company, $request->all());
        return response()->json(['message' => 'Company updated.', 'company' => $c]);
    }

    /** POST /superadmin/companies/{company}/reset-api-key — rotate the platform app_id + private_token pair. */
    public function resetApiKey(Company $company): JsonResponse
    {
        $result = $this->superAdminService->resetApiKey($company);
        return response()->json([
            'message'       => 'API key reset. Store it safely — the private token is shown only once.',
            'app_id'        => $result['app_id'],
            'private_token' => $result['private_token'],
        ]);
    }

    // ── Company Config (full field set) ─────────────────────────────────────────

    /** GET /superadmin/companies/{company}/config */
    public function showCompanyConfig(Company $company): JsonResponse
    {
        return response()->json(['config' => new SuperAdminCompanyConfigResource($company)]);
    }

    /** PUT /superadmin/companies/{company}/config */
    public function updateCompanyConfig(Request $request, Company $company): JsonResponse
    {
        $data = $request->validate([
            'name'                     => ['sometimes', 'string', 'max:100'],
            'email'                    => ['sometimes', 'nullable', 'email'],
            'phone'                    => ['sometimes', 'nullable', 'string', 'max:20'],
            'website'                  => ['sometimes', 'nullable', 'string', 'max:150'],
            'status'                   => ['sometimes', \Illuminate\Validation\Rule::in(['active', 'trial', 'suspended', 'expired'])],
            'suspended_reason'         => ['sometimes', 'nullable', 'string', 'max:255'],
            'industry_template'        => ['sometimes', \Illuminate\Validation\Rule::in(array_keys(config('industry_templates', [])))],
            'max_devices_per_user'     => ['sometimes', 'integer', 'min:1', 'max:20'],

            'trial_ends_at'            => ['sometimes', 'nullable', 'date'],
            'plan_expires_at'          => ['sometimes', 'nullable', 'date'],

            'storage_limit_bytes'      => ['sometimes', 'integer', 'min:0'],

            'wa_phone_id'              => ['sometimes', 'nullable', 'string', 'max:100'],
            'wa_business_id'           => ['sometimes', 'nullable', 'string', 'max:100'],
            'meta_app_id'              => ['sometimes', 'nullable', 'string', 'max:100'],
            'wa_profile_id'            => ['sometimes', 'nullable', 'string', 'max:100'],
            'wa_webhook_token'         => ['sometimes', 'nullable', 'string', 'max:150'],
            'wa_access_token'          => ['sometimes', 'nullable', 'string', 'max:2000'],

            'wa_auth_enabled'          => ['sometimes', 'boolean'],
            'wa_chat_token'            => ['sometimes', 'nullable', 'string', 'max:150'],
            'wa_chat_token_expires_at' => ['sometimes', 'nullable', 'date'],

            'waha_enabled'             => ['sometimes', 'boolean'],
            'waha_max_sessions'        => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'waha_max_webhooks'        => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'waha_media_limit_mb'      => ['sometimes', 'integer', 'min:0'],

            'settings'                 => ['sometimes', 'nullable'],
        ]);

        $company = $this->superAdminService->updateCompanyConfig($company, $data);

        return response()->json(['message' => 'Company config updated.', 'config' => new SuperAdminCompanyConfigResource($company)]);
    }

    // ── Observability: failed queue jobs ──────────────────────────────────────────

    public function failedJobs(Request $request): JsonResponse
    {
        $paginator = $this->superAdminService->failedJobs($request->all());

        $paginator->getCollection()->transform(function ($row) {
            $payload = json_decode($row->payload, true) ?: [];
            $jobClass = $payload['displayName'] ?? ($payload['job'] ?? 'Unknown job');

            return [
                'id'             => $row->id,
                'uuid'           => $row->uuid,
                'connection'     => $row->connection,
                'queue'          => $row->queue,
                'job'            => $jobClass,
                'exception'      => \Illuminate\Support\Str::limit((string) $row->exception, 300),
                'exception_full' => $row->exception,
                'failed_at'      => $row->failed_at,
            ];
        });

        return response()->json($paginator);
    }

    public function retryFailedJob(string $uuid): JsonResponse
    {
        $this->superAdminService->retryFailedJob($uuid);
        return response()->json(['message' => 'Job re-queued.']);
    }

    public function deleteFailedJob(string $uuid): JsonResponse
    {
        $ok = $this->superAdminService->deleteFailedJob($uuid);
        return $ok
            ? response()->json(['message' => 'Job deleted.'])
            : response()->json(['message' => 'Not found.'], 404);
    }

    public function flushFailedJobs(): JsonResponse
    {
        $n = $this->superAdminService->flushFailedJobs();
        return response()->json(['message' => "Deleted {$n} failed job(s)."]);
    }

    // ── AI response testing (superadmin's own token — no impersonation needed) ───

    /** GET /superadmin/companies/{company}/ai-settings — resolved provider/model/keys for any company. */
    public function companyAiSettings(Company $company): JsonResponse
    {
        return response()->json(\App\Modules\WaChat\Http\Controllers\AiAgentController::buildAiSettings($company));
    }

    /**
     * POST /superadmin/ai-test — ask the AI agent a question as any company, using the
     * superadmin's own session. Leaves no trace in that company's data (see
     * RagOrchestrator::answer()'s $isTest param) — no Contact/Lead, no lingering session.
     */
    public function aiTest(Request $request, \App\Modules\WaChat\Services\Rag\RagOrchestrator $rag): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'query'      => ['required', 'string', 'max:2000'],
            'ai_config'  => ['nullable', 'array'],
        ]);

        $result = $rag->answer(
            query:         $data['query'],
            contactPhone:  '00000000000',
            wahaSessionId: 'superadmin-test',
            companyId:     (int) $data['company_id'],
            aiConfig:      $data['ai_config'] ?? [],
            isTest:        true,
        );

        return response()->json($result);
    }

    // ── WA Chat gateway token (open-wa) ──────────────────────────────────────────

    /** GET /superadmin/companies/{company}/wa-chat/status — live check against the gateway. */
    public function waChatStatus(Company $company, WaChatTokenService $waChatTokens): JsonResponse
    {
        return response()->json($waChatTokens->status($company));
    }

    /** POST /superadmin/companies/{company}/wa-chat/provision — mint a fresh gateway key. */
    public function waChatProvision(Company $company, WaChatTokenService $waChatTokens): JsonResponse
    {
        try {
            $waChatTokens->provision($company);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response()->json([
            'message' => 'WA Chat token provisioned.',
            'status'  => $waChatTokens->status($company->fresh()),
        ]);
    }

    // ── Observability: API request log / activity feed ──────────────────────────

    public function apiLogs(Request $request, WaChatTokenService $waChatTokens): JsonResponse
    {
        if ($request->input('source') === 'backend_node') {
            return $this->backendNodeActivity($request, $waChatTokens);
        }

        return response()->json($this->superAdminService->apiLogs($request->all()));
    }

    public function apiLogStats(Request $request): JsonResponse
    {
        if ($request->input('source') === 'backend_node') {
            // Per-day/per-route breakdowns aren't worth proxying for this — the list view
            // (with its own total) already tells the story; the dashboard stays Laravel-only.
            return response()->json(['range_days' => 0, 'total_requests' => 0, 'error_count' => 0,
                'error_rate' => 0, 'avg_duration_ms' => 0, 'requests_today' => 0,
                'by_day' => [], 'top_companies' => [], 'top_routes' => [], 'status_breakdown' => []]);
        }

        return response()->json($this->superAdminService->apiLogStats($request->all()));
    }

    /** Live proxy — every action backend-node's own audit trail recorded, any severity. */
    private function backendNodeActivity(Request $request, WaChatTokenService $waChatTokens): JsonResponse
    {
        $perPage = max(1, (int) $request->input('per_page', 40));
        $page    = max(1, (int) $request->input('page', 1));

        try {
            $result = $waChatTokens->gatewayActivity($perPage, ($page - 1) * $perPage);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        $rows = collect($result['data'])->map(fn ($row) => [
            'id'          => $row['id'] ?? null,
            'created_at'  => $row['createdAt'] ?? null,
            'company'     => null,
            'user'        => !empty($row['apiKeyName']) ? ['name' => $row['apiKeyName']] : null,
            'actor_role'  => null,
            'method'      => $row['method'] ?? '—',
            'path'        => $row['path'] ?? ($row['sessionName'] ?? ''),
            'route_name'  => $row['action'] ?? null,
            'status_code' => $row['statusCode'] ?? (($row['severity'] ?? 'info') === 'error' ? 500 : 200),
            'duration_ms' => 0,
            'is_error'    => ($row['severity'] ?? 'info') === 'error',
        ])->values();

        return response()->json([
            'data'         => $rows,
            'total'        => $result['total'],
            'current_page' => $page,
            'per_page'     => $perPage,
            'last_page'    => (int) ceil(max($result['total'], 1) / $perPage),
        ]);
    }

    // ── Observability: error log (Laravel / frontend, stored — or backend_node, proxied live) ────

    public function errorLogs(Request $request, WaChatTokenService $waChatTokens): JsonResponse
    {
        if ($request->input('source') === 'backend_node') {
            return $this->backendNodeErrors($request, $waChatTokens);
        }

        return response()->json($this->superAdminService->errorLogs($request->all()));
    }

    public function showError(int $id): JsonResponse
    {
        return response()->json(['error' => $this->superAdminService->errorLog($id)]);
    }

    /** DELETE /superadmin/errors?source=laravel|frontend|backend_node — wipe that source's trail. */
    public function clearErrorLogs(Request $request, WaChatTokenService $waChatTokens): JsonResponse
    {
        $source = (string) $request->input('source', 'laravel');

        if ($source === 'backend_node') {
            try {
                $deleted = $waChatTokens->clearGatewayErrors();
            } catch (\Throwable $e) {
                return response()->json(['message' => $e->getMessage()], 502);
            }
            return response()->json(['message' => "Cleared {$deleted} gateway error log(s).", 'deleted' => $deleted]);
        }

        $deleted = $this->superAdminService->clearErrorLogs($source);
        return response()->json(['message' => "Cleared {$deleted} error log(s).", 'deleted' => $deleted]);
    }

    /** Live proxy — nothing from backend-node's own audit trail is stored in Flowexa's DB. */
    private function backendNodeErrors(Request $request, WaChatTokenService $waChatTokens): JsonResponse
    {
        $perPage = max(1, (int) $request->input('per_page', 40));
        $page    = max(1, (int) $request->input('page', 1));

        try {
            $result = $waChatTokens->gatewayErrors($perPage, ($page - 1) * $perPage);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        $rows = collect($result['data'])->map(fn ($row) => [
            'id'              => $row['id'] ?? null,
            'created_at'      => $row['createdAt'] ?? null,
            'company'         => null,
            'user'            => null,
            'actor_role'      => null,
            'exception_class' => $row['action'] ?? 'gateway_error',
            'message'         => $row['errorMessage'] ?: ($row['sessionName'] ?? $row['apiKeyName'] ?? '—'),
            'file'            => $row['sessionName'] ?? $row['apiKeyName'] ?? null,
            'line'            => null,
        ])->values();

        return response()->json([
            'data'         => $rows,
            'total'        => $result['total'],
            'current_page' => $page,
            'per_page'     => $perPage,
            'last_page'    => (int) ceil(max($result['total'], 1) / $perPage),
        ]);
    }

    // ── Observability: raw Laravel log file viewer ────────────────────────────────

    public function systemLogFiles(): JsonResponse
    {
        return response()->json(['files' => $this->superAdminService->systemLogFiles()]);
    }

    public function systemLog(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file'   => ['required', 'string', 'max:100'],
            'lines'  => ['sometimes', 'integer', 'min:10', 'max:2000'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        return response()->json($this->superAdminService->systemLog(
            $data['file'],
            (int) ($data['lines'] ?? 300),
            $data['search'] ?? null
        ));
    }

    /** DELETE /superadmin/system-log — truncate a log file in place. */
    public function clearSystemLog(Request $request): JsonResponse
    {
        $data = $request->validate(['file' => ['required', 'string', 'max:100']]);

        $ok = $this->superAdminService->clearSystemLog($data['file']);

        return $ok
            ? response()->json(['message' => "{$data['file']} cleared."])
            : response()->json(['message' => 'File not found.'], 404);
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

    /**
     * GET /superadmin/permissions — the global role editor.
     *
     * Only system roles (company_id IS NULL) are managed here. Per-company
     * roles have their own editor at /superadmin/companies/{company}/permissions.
     */
    public function permissions(): \Illuminate\Http\JsonResponse
    {
        $roles = \App\Models\Role::whereNull('company_id')
            ->orderBy('sort_order')->orderBy('id')
            ->withCount('users')
            ->get()
            ->map(fn ($r) => [
                'id'          => $r->id,
                'name'        => $r->name,
                'label'       => $r->label,
                'description' => $r->description,
                'company'     => null,
                'company_id'  => null,
                'is_system'   => (bool) $r->is_system,
                'protected'   => in_array($r->name, self::PROTECTED_ROLE_NAMES, true),
                'user_count'  => $r->users_count,
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

    /**
     * PUT /superadmin/permissions/{roleId} — update a system role's permissions.
     *
     * Only global roles (company_id IS NULL) are editable here; superadmin and
     * owner are locked to full access. Submitted keys are checked against the
     * catalogue: unknown-and-new keys are rejected, while legacy keys already
     * on the role are preserved (some route middleware still checks those).
     */
    public function updatePermissions(\Illuminate\Http\Request $request, int $roleId): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'permissions'   => ['present', 'array'],
            'permissions.*' => ['string'],
        ]);

        $role = \App\Models\Role::whereNull('company_id')->find($roleId);
        if (! $role) {
            return response()->json(['message' => 'System role not found.'], 404);
        }

        if (in_array($role->name, self::PROTECTED_ROLE_NAMES, true)) {
            return response()->json([
                'message' => ucfirst($role->name) . ' always has full access and cannot be edited.',
            ], 403);
        }

        $submitted     = array_values(array_unique($data['permissions']));
        $catalogueKeys = \App\Models\Permission::pluck('id', 'key');   // key => id
        $existingKeys  = $role->permissions ?? [];

        $unknownNew = array_values(array_filter(
            $submitted,
            fn ($k) => ! $catalogueKeys->has($k) && ! in_array($k, $existingKeys, true)
        ));

        // Pivot table: only real catalogue permissions.
        $permIds = $catalogueKeys->only($submitted)->values()->all();
        $role->permissionRelations()->sync($permIds);

        // JSON column: catalogue keys + any legacy key that is still submitted.
        $jsonKeys = array_values(array_filter(
            $submitted,
            fn ($k) => $catalogueKeys->has($k) || in_array($k, $existingKeys, true)
        ));
        $role->update(['permissions' => $jsonKeys]);

        return response()->json([
            'message'         => 'Permissions updated.',
            'role'            => [
                'id'          => $role->id,
                'name'        => $role->name,
                'label'       => $role->label,
                'permissions' => $role->permissions ?? [],
            ],
            'ignored_unknown' => $unknownNew,
        ]);
    }
}
