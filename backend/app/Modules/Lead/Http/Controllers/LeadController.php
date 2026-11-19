<?php

namespace App\Modules\Lead\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Lead\DTOs\AssignLeadDTO;
use App\Modules\Lead\DTOs\BulkAssignDTO;
use App\Modules\Lead\DTOs\CreateLeadDTO;
use App\Modules\Lead\DTOs\CreateNoteDTO;
use App\Modules\Lead\DTOs\ImportLeadDTO;
use App\Modules\Lead\DTOs\LeadFilterDTO;
use App\Modules\Lead\DTOs\UpdateLeadDTO;
use App\Modules\Lead\Http\Requests\AssignLeadRequest;
use App\Modules\Lead\Http\Requests\BulkAssignRequest;
use App\Modules\Lead\Http\Requests\CreateLeadRequest;
use App\Modules\Lead\Http\Requests\CreateNoteRequest;
use App\Modules\Lead\Http\Requests\ImportLeadRequest;
use App\Modules\Lead\Http\Requests\LeadFilterRequest;
use App\Modules\Lead\Http\Requests\UpdateLeadRequest;
use App\Modules\Lead\Http\Resources\LeadCollection;
use App\Modules\Lead\Http\Resources\LeadResource;
use App\Modules\Lead\Services\LeadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LeadController extends Controller
{
    public function __construct(private readonly LeadService $leadService) {}

    private function canViewAll(): bool
    {
        return auth()->user()->hasPermission('leads.view_all');
    }

    public function index(LeadFilterRequest $request): JsonResponse
    {
        $paginator = $this->leadService->list(
            auth()->user()->company_id, auth()->id(), $this->canViewAll(),
            LeadFilterDTO::fromRequest($request->validated())
        );
        return (new LeadCollection($paginator))->response();
    }

    // Unpaginated leads (id/name/phone) matching the filters — feeds the wa-chat
    // message-sender "Lead" recipient tab, which resolves a from/to date range
    // straight to a phone list rather than picking leads one by one.
    public function recipients(LeadFilterRequest $request): JsonResponse
    {
        $leads = $this->leadService->recipients(
            auth()->user()->company_id, auth()->id(), $this->canViewAll(),
            LeadFilterDTO::fromRequest($request->validated())
        );

        return response()->json(['data' => $leads->map(fn($l) => [
            'id'    => $l->id,
            'name'  => $l->contact?->name,
            'phone' => $l->contact?->phone,
        ])->filter(fn($r) => $r['phone'])->values()]);
    }

    public function show(int $lead): JsonResponse
    {
        $l = $this->leadService->show($lead, auth()->user()->company_id, auth()->id(), $this->canViewAll());
        return response()->json(['lead' => new LeadResource($l)]);
    }

    public function store(CreateLeadRequest $request): JsonResponse
    {
        $lead = $this->leadService->create(auth()->user()->company_id, CreateLeadDTO::fromRequest($request->validated()));
        return response()->json(['message' => 'Lead created.', 'lead' => new LeadResource($lead)], 201);
    }

    public function update(UpdateLeadRequest $request, int $lead): JsonResponse
    {
        $l = $this->leadService->update($lead, auth()->user()->company_id, auth()->id(), $this->canViewAll(), UpdateLeadDTO::fromRequest($request->validated()));
        return response()->json(['message' => 'Lead updated.', 'lead' => new LeadResource($l)]);
    }

    public function assign(AssignLeadRequest $request, int $lead): JsonResponse
    {
        $l = $this->leadService->assign($lead, auth()->user()->company_id, AssignLeadDTO::fromRequest($request->validated()));
        return response()->json(['message' => 'Lead assigned.', 'lead' => new LeadResource($l)]);
    }

    public function bulkAssign(BulkAssignRequest $request): JsonResponse
    {
        $count = $this->leadService->bulkAssign(auth()->user()->company_id, BulkAssignDTO::fromRequest($request->validated()));
        return response()->json(['message' => "{$count} leads assigned (round-robin)."]);
    }

    /** POST /leads/auto-assign — { strategy: algorithm|round_robin, lead_ids?: [] (none = all unassigned) } */
    public function autoAssign(Request $request): JsonResponse
    {
        $d = $request->validate([
            'strategy'   => ['required', 'in:algorithm,round_robin'],
            'lead_ids'   => ['nullable', 'array'],
            'lead_ids.*' => ['integer'],
            'staff_scope' => ['nullable', 'in:all,online,selected,selected_online'],   // omitted = follow the routing rules
            'staff_ids'   => ['nullable', 'array'],
            'staff_ids.*' => ['integer', \Illuminate\Validation\Rule::exists('users', 'id')->where('company_id', auth()->user()->company_id)],
        ]);
        if (in_array($d['staff_scope'] ?? null, ['selected', 'selected_online'], true) && empty($d['staff_ids'])) {
            return response()->json(['message' => 'Pick at least one employee.', 'errors' => ['staff_ids' => ['Pick at least one employee.']]], 422);
        }
        if (in_array($d['staff_scope'] ?? null, \App\Services\LeadAssignment\StaffPoolResolver::ATTENDANCE_SCOPES, true)
            && !app(\App\Services\LeadAssignment\StaffPoolResolver::class)->attendanceAvailable((int) auth()->user()->company_id)) {
            return response()->json(['message' => 'Attendance-based assignment needs the HR section, which is not available on your plan.', 'error_code' => 'hr_not_available'], 422);
        }
        $company = auth()->user()->company;
        if (!in_array($d['strategy'], \App\Support\Entitlements::forCompany($company)['assign_methods'], true)) {
            return response()->json(['message' => '"' . \App\Support\Modules::ASSIGN_METHODS[$d['strategy']] . '" assignment is not part of your plan.', 'error_code' => 'plan_feature_disabled'], 403);
        }
        $r = $this->leadService->autoAssign($company->id, $d['lead_ids'] ?? [], $d['strategy'], $d['staff_scope'] ?? null, $d['staff_ids'] ?? null);
        return response()->json(['message' => "{$r['assigned']} lead(s) auto-assigned" . ($r['skipped'] ? ", {$r['skipped']} left (no available staff)." : '.'), ...$r]);
    }

    /** POST /leads/recall — take assigned-but-pending leads back (many at once). preview=true only counts. */
    public function recall(Request $request): JsonResponse
    {
        $d = $request->validate([
            'lead_ids'         => ['nullable', 'array'],
            'lead_ids.*'       => ['integer'],
            'only_untouched'   => ['nullable', 'boolean'],   // default: true when recalling by filter, false for hand-picked leads
            'staff_id'         => ['nullable', 'integer'],
            'older_than_hours' => ['nullable', 'integer', 'min:1', 'max:8760'],
            'preview'          => ['nullable', 'boolean'],
        ]);
        $ids = $d['lead_ids'] ?? [];
        $r = $this->leadService->recall(
            auth()->user()->company_id, $ids, (bool) ($d['only_untouched'] ?? !$ids),
            $d['staff_id'] ?? null, $d['older_than_hours'] ?? null, (bool) ($d['preview'] ?? false),
        );
        return response()->json($r + ['message' => ($d['preview'] ?? false) ? "{$r['count']} lead(s) would be recalled." : "{$r['count']} lead(s) recalled — they are unassigned again."]);
    }

    public function bulkReassign(\App\Modules\Lead\Http\Requests\BulkReassignRequest $request): JsonResponse
    {
        $count = $this->leadService->bulkReassign(
            auth()->user()->company_id,
            \App\Modules\Lead\DTOs\BulkReassignDTO::fromRequest($request->validated()),
        );
        return response()->json(['message' => "{$count} lead(s) moved.", 'count' => $count]);
    }

    public function destroy(int $lead): JsonResponse
    {
        $this->leadService->delete($lead, auth()->user()->company_id);
        return response()->json(['message' => 'Lead deleted.']);
    }

    public function bulkDelete(\App\Modules\Lead\Http\Requests\BulkDeleteRequest $request): JsonResponse
    {
        $count = $this->leadService->bulkDelete(
            auth()->user()->company_id,
            \App\Modules\Lead\DTOs\BulkDeleteDTO::fromRequest($request->validated()),
        );
        return response()->json(['message' => "{$count} lead(s) deleted.", 'count' => $count]);
    }

    /** POST /leads/bulk-status — change stage and/or priority of the selected leads. */
    public function bulkStatus(\App\Modules\Lead\Http\Requests\BulkStatusRequest $request): JsonResponse
    {
        $d = $request->validated();
        $count = $this->leadService->bulkStatus(auth()->user()->company_id, $d['lead_ids'], $d['stage'] ?? null, $d['priority'] ?? null);
        return response()->json(['message' => "{$count} lead(s) updated.", 'count' => $count]);
    }

    public function crmSync(int $lead): JsonResponse
    {
        $this->leadService->crmSync($lead, auth()->user()->company_id);
        return response()->json(['message' => 'Lead queued for CRM sync.']);
    }

    public function analytics(): JsonResponse
    {
        return response()->json(['analytics' => $this->leadService->analytics(auth()->user()->company_id)]);
    }

    /** GET /leads/staff-overview — every active employee's pipeline, workload and routing stats. */
    public function staffOverview(\App\Modules\Lead\Services\StaffLeadInsights $insights): JsonResponse
    {
        return response()->json(['staff' => $insights->overview(auth()->user()->company_id)]);
    }

    /** GET /leads/staff-overview/{staff} — one employee in full, with recent assignments and activity. */
    public function staffDetail(\App\Modules\Lead\Services\StaffLeadInsights $insights, int $staff): JsonResponse
    {
        $d = $insights->detail(auth()->user()->company_id, $staff);
        abort_unless($d, 404, 'Employee not found.');
        return response()->json(['staff' => $d]);
    }

    /** GET /leads/sources — the canonical "Lead Source" list (config/lead_sources.php), for filter/create dropdowns. */
    public function sources(): JsonResponse
    {
        return response()->json(['sources' => config('lead_sources', [])]);
    }

    /** GET /leads/logs — company-wide feed of lead activity (assigned, accepted, declined, timed out & reassigned, stage changes, notes, AI hand-off…), newest first. */
    public function logs(Request $request): JsonResponse
    {
        $filter = $request->validate([
            'event'    => ['nullable', 'string', 'max:60'],
            'from'     => ['nullable', 'date'],
            'to'       => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
            'page'     => ['nullable', 'integer', 'min:1'],
        ]);

        $page = $this->leadService->logs(auth()->user()->company_id, auth()->id(), $this->canViewAll(), $filter);

        $page->getCollection()->transform(fn ($e) => [
            'id'         => $e->id,
            'event'      => $e->event,
            'payload'    => $e->payload,
            'user'       => $e->user?->name,
            'created_at' => $e->created_at->toIso8601String(),
            'lead'       => $e->lead ? [
                'id'          => $e->lead->id,
                'stage'       => $e->lead->stage,
                'source'      => $e->lead->source,
                'category'    => $e->lead->category,
                'origin'      => $e->lead->origin_label,
                'contact'     => $e->lead->contact ? ['name' => $e->lead->contact->name, 'phone' => $e->lead->contact->phone] : null,
                'assigned_to' => $e->lead->assignedTo?->name,
            ] : null,
        ]);

        return response()->json($page);
    }

    public function import(ImportLeadRequest $request): JsonResponse
    {
        $import = $this->leadService->import(
            companyId: auth()->user()->company_id,
            userId: auth()->id(),
            dto: ImportLeadDTO::fromRequest($request->validated()),
        );

        return response()->json(['message' => 'Lead import started.', 'import' => $import], 202);
    }



    public function export(LeadFilterRequest $request): StreamedResponse
    {
        $path = $this->leadService->export(
            companyId: auth()->user()->company_id,
            filter:    LeadFilterDTO::fromRequest($request->validated()),
        );

        return Storage::download($path, 'leads.csv');
    }
}
