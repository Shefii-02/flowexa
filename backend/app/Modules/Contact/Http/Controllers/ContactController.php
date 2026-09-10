<?php

namespace App\Modules\Contact\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\CampaignContact;
use App\Models\Contact;
use App\Models\Lead;
use App\Modules\Contact\DTOs\ContactFilterDTO;
use App\Modules\Contact\DTOs\CreateContactDTO;
use App\Modules\Contact\DTOs\ImportContactDTO;
use App\Modules\Contact\DTOs\UpdateContactDTO;
use App\Modules\Contact\Http\Requests\ContactFilterRequest;
use App\Modules\Contact\Http\Requests\CreateContactRequest;
use App\Modules\Contact\Http\Requests\ImportContactRequest;
use App\Modules\Contact\Http\Requests\SyncLabelsRequest;
use App\Modules\Contact\Http\Requests\UpdateContactRequest;
use App\Modules\Contact\Http\Resources\ContactCollection;
use App\Modules\Contact\Http\Resources\ContactResource;
use App\Modules\Contact\Http\Resources\ImportResultResource;
use App\Modules\Contact\Services\ContactService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ContactController extends Controller
{
    public function __construct(
        private readonly ContactService $contactService,
    ) {}

    // ─── GET /contacts ────────────────────────────────────────────────────────
    public function index(ContactFilterRequest $request): JsonResponse
    {
        $paginator = $this->contactService->list(
            companyId: auth()->user()->company_id,
            filter: ContactFilterDTO::fromRequest($request->validated()),
        );

        return (new ContactCollection($paginator))->response();
    }

    // ─── GET /contacts/{id} ───────────────────────────────────────────────────
    public function show(int $contact): JsonResponse
    {
        $c = $this->contactService->show($contact, auth()->user()->company_id);

        return response()->json(['contact' => new ContactResource($c)]);
    }

    // ─── POST /contacts ───────────────────────────────────────────────────────
    public function store(CreateContactRequest $request): JsonResponse
    {
        $contact = $this->contactService->create(
            companyId: auth()->user()->company_id,
            dto: CreateContactDTO::fromRequest($request->validated()),
        );

        return response()->json([
            'message' => 'Contact created.',
            'contact' => new ContactResource($contact),
        ], 201);
    }

    // ─── PUT /contacts/{id} ───────────────────────────────────────────────────
    public function update(UpdateContactRequest $request, int $contact): JsonResponse
    {

        Log::info('Updating contact with data: ' . json_encode($request->validated()));
        $c = $this->contactService->update(
            id: $contact,
            companyId: auth()->user()->company_id,
            dto: UpdateContactDTO::fromRequest($request->validated()),
        );

        return response()->json([
            'message' => 'Contact updated.',
            'contact' => new ContactResource($c),
        ]);
    }

    // ─── POST /contacts/{id}/labels ───────────────────────────────────────────
    public function syncLabels(SyncLabelsRequest $request, int $contact): JsonResponse
    {
        $c = $this->contactService->syncLabels(
            id: $contact,
            companyId: auth()->user()->company_id,
            labelIds: $request->validated('label_ids'),
        );

        return response()->json([
            'message' => 'Labels synced.',
            'contact' => new ContactResource($c),
        ]);
    }

    // ─── DELETE /contacts/{id}/labels/{label} ─────────────────────────────────
    public function removeLabel(int $contact, int $label): JsonResponse
    {
        $c = $this->contactService->removeLabel(
            contactId: $contact,
            companyId: auth()->user()->company_id,
            labelId: $label
        );

        return response()->json([
            'message' => 'Label removed.',
            'contact' => new ContactResource($c),
        ]);
    }

    // ─── PATCH /contacts/{id}/opt-out ─────────────────────────────────────────
    public function optOut(int $contact): JsonResponse
    {
        $c = $this->contactService->optOut($contact, auth()->user()->company_id);

        return response()->json([
            'message'  => 'Contact opted out.',
            'opted_in' => false,
        ]);
    }

    // ─── PATCH /contacts/{id}/opt-in ──────────────────────────────────────────
    public function optIn(int $contact): JsonResponse
    {
        $c = $this->contactService->optIn($contact, auth()->user()->company_id);

        return response()->json([
            'message'  => 'Contact opted in.',
            'opted_in' => true,
        ]);
    }

    // ─── GET /contacts/{id}/leads ────────────────────────────────────────────
    // Leads for this contact, each with its recent activity timeline (lead_events).
    public function leads(int $contact): JsonResponse
    {
        $companyId = auth()->user()->company_id;
        $this->contactService->show($contact, $companyId); // 404s if not this company's contact

        $leads = Lead::where('company_id', $companyId)
            ->where('contact_id', $contact)
            ->with(['assignedTo:id,name', 'events' => fn ($q) => $q->with('user:id,name')->latest()->limit(20)])
            ->latest()
            ->get()
            ->map(fn (Lead $l) => [
                'id'          => $l->id,
                'stage'       => $l->stage,
                'priority'    => $l->priority,
                'category'    => $l->category,
                'source'      => $l->source,
                'notes'       => $l->notes,
                'assigned_to' => $l->assignedTo?->name,
                'created_at'  => $l->created_at?->toIso8601String(),
                'activities'  => $l->events->map(fn ($e) => [
                    'id'         => $e->id,
                    'event'      => $e->event,
                    'payload'    => $e->payload,
                    'by'         => $e->user?->name,
                    'created_at' => $e->created_at?->toIso8601String(),
                ]),
            ]);

        return response()->json(['leads' => $leads]);
    }

    // ─── GET /contacts/{id}/campaigns ────────────────────────────────────────
    // Broadcast campaigns this contact was included in + their per-recipient status.
    public function campaigns(int $contact): JsonResponse
    {
        $companyId = auth()->user()->company_id;
        $this->contactService->show($contact, $companyId);

        $rows = CampaignContact::where('contact_id', $contact)
            ->whereHas('campaign', fn ($q) => $q->where('company_id', $companyId))
            ->with('campaign:id,name,created_at')
            ->latest()
            ->get()
            ->map(fn (CampaignContact $cc) => [
                'campaign_id'   => $cc->campaign_id,
                'campaign_name' => $cc->campaign?->name ?? "Campaign #{$cc->campaign_id}",
                'status'        => $cc->status,
                'failed_reason' => $cc->failed_reason,
                'sent_at'       => $cc->sent_at?->toIso8601String(),
                'delivered_at'  => $cc->delivered_at?->toIso8601String(),
                'read_at'       => $cc->read_at?->toIso8601String(),
            ]);

        return response()->json(['campaigns' => $rows]);
    }

    // ─── DELETE /contacts/{id} ────────────────────────────────────────────────
    public function destroy(int $contact): JsonResponse
    {
        $this->contactService->delete($contact, auth()->user()->company_id);

        return response()->json(['message' => 'Contact deleted.']);
    }

    // ─── POST /contacts/import ────────────────────────────────────────────────
    public function import(ImportContactRequest $request): JsonResponse
    {
        $file    = $request->file('file');
        $path    = $file->store('imports/' . auth()->user()->company_id, 'local');

        $result  = $this->contactService->import(
            companyId: auth()->user()->company_id,
            dto: ImportContactDTO::fromRequest($request->validated(), $path),
        );

        return response()->json([
            'message' => "Import complete: {$result->imported} imported, {$result->skipped} skipped.",
            'result'  => ImportResultResource::toArray($result),
        ]);
    }

    // ─── POST /contacts/by-labels ─────────────────────────────────────────────
    // Returns contacts that have ANY of the given label IDs, with their phone numbers.
    // Used by Message Sender to expand label recipients into individual contacts.
    public function byLabels(Request $request): JsonResponse
    {
        $data = $request->validate([
            'label_ids' => 'required|array',
            'label_ids.*' => 'integer',
        ]);

        $companyId = auth()->user()->company_id;
        $labelIds  = $data['label_ids'];

        // Contacts → labels is a many-to-many through contact_label_pivot.
        $contacts = Contact::where('company_id', $companyId)
            ->whereHas('labels', fn($q) => $q->whereIn('contact_labels.id', $labelIds))
            ->select('id', 'name', 'phone')
            ->get();

        return response()->json(['data' => $contacts]);
    }

    // ─── GET /contacts/export ─────────────────────────────────────────────────
    public function export(ContactFilterRequest $request): StreamedResponse
    {
        $path = $this->contactService->export(
            companyId: auth()->user()->company_id,
            filter: ContactFilterDTO::fromRequest($request->validated()),
        );

        return Storage::download($path, 'contacts.csv');
    }
}
