<?php

namespace App\Modules\Contact\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Contact\DTOs\CreateLabelDTO;
use App\Modules\Contact\DTOs\UpdateLabelDTO;
use App\Modules\Contact\Http\Requests\CreateLabelRequest;
use App\Modules\Contact\Http\Requests\UpdateLabelRequest;
use App\Modules\Contact\Http\Resources\LabelResource;
use App\Modules\Contact\Services\LabelService;
use Illuminate\Http\JsonResponse;

class LabelController extends Controller
{
    public function __construct(
        private readonly LabelService $labelService,
    ) {}

    // ─── GET /labels ──────────────────────────────────────────────────────────
    public function index(): JsonResponse
    {
        $labels = $this->labelService->list(auth()->user()->company_id);

        return response()->json([
            'labels' => LabelResource::collection($labels),
        ]);
    }

    // ─── GET /labels/{id} ────────────────────────────────────────────────────
    public function show(int $label): JsonResponse
    {
        $l = $this->labelService->show($label, auth()->user()->company_id);

        return response()->json(['label' => new LabelResource($l)]);
    }

    // ─── POST /labels ─────────────────────────────────────────────────────────
    public function store(CreateLabelRequest $request): JsonResponse
    {
        $label = $this->labelService->create(
            companyId: auth()->user()->company_id,
            dto:       CreateLabelDTO::fromRequest($request->validated()),
        );

        return response()->json([
            'message' => 'Label created.',
            'label'   => new LabelResource($label),
        ], 201);
    }

    // ─── PUT /labels/{id} ────────────────────────────────────────────────────
    public function update(UpdateLabelRequest $request, int $label): JsonResponse
    {
        $l = $this->labelService->update(
            id:        $label,
            companyId: auth()->user()->company_id,
            dto:       UpdateLabelDTO::fromRequest($request->validated()),
        );

        return response()->json([
            'message' => 'Label updated.',
            'label'   => new LabelResource($l),
        ]);
    }

    // ─── DELETE /labels/{id} ─────────────────────────────────────────────────
    public function destroy(int $label): JsonResponse
    {
        $this->labelService->delete($label, auth()->user()->company_id);

        return response()->json(['message' => 'Label deleted.']);
    }
}
