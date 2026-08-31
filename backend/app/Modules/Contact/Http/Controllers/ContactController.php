<?php

namespace App\Modules\Contact\Http\Controllers;

use App\Http\Controllers\Controller;
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

        // Contacts → labels is a many-to-many through contact_labels pivot table.
        $contacts = \App\Modules\Contact\Models\Contact::where('company_id', $companyId)
            ->whereHas('labels', fn($q) => $q->whereIn('labels.id', $labelIds))
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
