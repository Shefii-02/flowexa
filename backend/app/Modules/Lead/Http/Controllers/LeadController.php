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

    public function destroy(int $lead): JsonResponse
    {
        $this->leadService->delete($lead, auth()->user()->company_id);
        return response()->json(['message' => 'Lead deleted.']);
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
