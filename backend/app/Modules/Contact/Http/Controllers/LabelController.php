<?php

namespace App\Modules\Contact\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ContactLabel;
use App\Modules\Contact\DTOs\CreateLabelDTO;
use App\Modules\Contact\DTOs\UpdateLabelDTO;
use App\Modules\Contact\Http\Requests\CreateLabelRequest;
use App\Modules\Contact\Http\Requests\UpdateLabelRequest;
use App\Modules\Contact\Http\Resources\LabelResource;
use App\Modules\Contact\Services\LabelService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LabelController extends Controller
{
    public function index(): JsonResponse
    {
        $labels = ContactLabel::where('company_id', auth()->user()->company_id)
            ->withCount('contacts')
            ->orderBy('name')
            ->get();
        return response()->json(['labels' => $labels]);
    }

    public function store(Request $request): JsonResponse
    {
        $d = $request->validate([
            'name'  => ['required', 'string', 'max:60'],
            'color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);
        // check plan limit
        $limit = auth()->user()->company->plan?->max_labels;
        if ($limit !== null) {
            $count = ContactLabel::where('company_id', auth()->user()->company_id)->count();
            if ($count >= $limit) {
                return response()->json(['message' => "Label limit ({$limit}) reached on your plan."], 422);
            }
        }
        $label = ContactLabel::create([
            'company_id' => auth()->user()->company_id,
            'name'       => $d['name'],
            'color'      => $d['color'],
        ]);
        return response()->json(['label' => $label], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $label = ContactLabel::where('id', $id)
            ->where('company_id', auth()->user()->company_id)
            ->firstOrFail();
        $d = $request->validate([
            'name'  => ['sometimes', 'string', 'max:60'],
            'color' => ['sometimes', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);
        $label->update($d);
        return response()->json(['label' => $label->fresh()]);
    }

    public function destroy(int $id): JsonResponse
    {
        $label = ContactLabel::where('id', $id)
            ->where('company_id', auth()->user()->company_id)
            ->firstOrFail();
        // detach from all contacts first
        $label->contacts()->detach();
        $label->delete();
        return response()->json(['message' => 'Label deleted.']);
    }
}

// class LabelController extends Controller
// {
//     public function __construct(
//         private readonly LabelService $labelService,
//     ) {}

//     // ─── GET /labels ──────────────────────────────────────────────────────────
//     public function index(): JsonResponse
//     {
//         $labels = $this->labelService->list(auth()->user()->company_id);

//         return response()->json([
//             'labels' => LabelResource::collection($labels),
//         ]);
//     }

//     // ─── GET /labels/{id} ────────────────────────────────────────────────────
//     public function show(int $label): JsonResponse
//     {
//         $l = $this->labelService->show($label, auth()->user()->company_id);

//         return response()->json(['label' => new LabelResource($l)]);
//     }

//     // ─── POST /labels ─────────────────────────────────────────────────────────
//     public function store(CreateLabelRequest $request): JsonResponse
//     {
//         $label = $this->labelService->create(
//             companyId: auth()->user()->company_id,
//             dto:       CreateLabelDTO::fromRequest($request->validated()),
//         );

//         return response()->json([
//             'message' => 'Label created.',
//             'label'   => new LabelResource($label),
//         ], 201);
//     }

//     // ─── PUT /labels/{id} ────────────────────────────────────────────────────
//     public function update(UpdateLabelRequest $request, int $label): JsonResponse
//     {
//         $l = $this->labelService->update(
//             id:        $label,
//             companyId: auth()->user()->company_id,
//             dto:       UpdateLabelDTO::fromRequest($request->validated()),
//         );

//         return response()->json([
//             'message' => 'Label updated.',
//             'label'   => new LabelResource($l),
//         ]);
//     }

//     // ─── DELETE /labels/{id} ─────────────────────────────────────────────────
//     public function destroy(int $label): JsonResponse
//     {
//         $this->labelService->delete($label, auth()->user()->company_id);

//         return response()->json(['message' => 'Label deleted.']);
//     }
// }
