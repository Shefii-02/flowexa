<?php

namespace App\Modules\Lead\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Lead\DTOs\AssignLeadDTO;
use App\Modules\Lead\DTOs\BulkAssignDTO;
use App\Modules\Lead\DTOs\CreateLeadDTO;
use App\Modules\Lead\DTOs\CreateNoteDTO;
use App\Modules\Lead\DTOs\LeadFilterDTO;
use App\Modules\Lead\DTOs\UpdateLeadDTO;
use App\Modules\Lead\Http\Requests\AssignLeadRequest;
use App\Modules\Lead\Http\Requests\BulkAssignRequest;
use App\Modules\Lead\Http\Requests\CreateLeadRequest;
use App\Modules\Lead\Http\Requests\CreateNoteRequest;
use App\Modules\Lead\Http\Requests\LeadFilterRequest;
use App\Modules\Lead\Http\Requests\UpdateLeadRequest;
use App\Modules\Lead\Http\Resources\LeadCollection;
use App\Modules\Lead\Http\Resources\LeadResource;
use App\Modules\Lead\Services\LeadService;
use Illuminate\Http\JsonResponse;

class LeadNoteController extends Controller
{
    public function __construct(private readonly LeadService $leadService) {}

    public function index(int $lead): JsonResponse
    {
        $l = $this->leadService->show($lead, auth()->user()->company_id, auth()->id(), auth()->user()->hasPermission('leads.view_all'));
        $notes = $l->events->where('event', 'note_added')->values();
        return response()->json(['notes' => $notes->map(fn($e) => ['id' => $e->id, 'content' => $e->payload['content'], 'user' => $e->user?->name, 'created_at' => $e->created_at->toIso8601String()])]);
    }

    public function store(CreateNoteRequest $request, int $lead): JsonResponse
    {
        $this->leadService->addNote($lead, auth()->user()->company_id, auth()->id(), auth()->user()->hasPermission('leads.view_all'), CreateNoteDTO::fromRequest($request->validated()));
        return response()->json(['message' => 'Note added.']);
    }

    public function destroy(int $lead, int $event): JsonResponse
    {
        \App\Models\LeadEvent::where('id', $event)->where('event', 'note_added')->delete();
        return response()->json(['message' => 'Note deleted.']);
    }


    public function events(int $lead): JsonResponse
    {
        $events = \App\Models\LeadEvent::where('lead_id', $lead)->get();

        return response()->json(['events' => $events]);

    }
}
