<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Listing;
use App\Modules\Catalog\IndustryTemplates;
use App\Modules\Catalog\ListingMatcher;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Validation\Rule;

class ListingController extends Controller
{
    public function __construct(private readonly ListingMatcher $matcher) {}

    /** Templates + this company's chosen vertical — powers the dynamic form. */
    public function templates(): JsonResponse
    {
        return response()->json([
            'templates' => IndustryTemplates::all(),
            'active'    => auth()->user()->company->industry_template ?? 'generic',
        ]);
    }

    public function setTemplate(Request $request): JsonResponse
    {
        $key = $request->validate([
            'industry_template' => ['required', Rule::in(IndustryTemplates::keys())],
        ])['industry_template'];
        auth()->user()->company->update(['industry_template' => $key]);
        return response()->json(['industry_template' => $key]);
    }

    public function index(Request $request): JsonResponse
    {
        $listings = Listing::where('company_id', auth()->user()->company_id)
            ->when($request->type, fn ($q, $t) => $q->where('type', $t))
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->q, fn ($q, $s) => $q->where('title', 'like', "%{$s}%"))
            ->orderBy('sort_order')->orderByDesc('updated_at')
            ->paginate(50);
        return response()->json($listings);
    }

    public function store(Request $request): JsonResponse
    {
        $d = $this->validated($request);
        $listing = Listing::create(array_merge($d, [
            'company_id' => auth()->user()->company_id,
            'created_by' => auth()->id(),
        ]));
        return response()->json(['message' => 'Listing added.', 'listing' => $listing], 201);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(['listing' => $this->find($id)]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $listing = $this->find($id);
        $listing->update($this->validated($request, false));
        return response()->json(['message' => 'Listing updated.', 'listing' => $listing->fresh()]);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->find($id)->delete();
        return response()->json(['message' => 'Listing deleted.']);
    }

    /** Dry-run the matcher (used by the agent, and to preview from the dashboard). */
    public function match(Request $request): JsonResponse
    {
        $requirements = $request->validate([
            'requirements' => ['required', 'array'],
        ])['requirements'];

        $company = auth()->user()->company;
        $results = $this->matcher->match($company->id, $company->industry_template, $requirements, $request->integer('limit', 5) ?: 5);

        return response()->json([
            'matches' => $results->map(fn ($r) => [
                'listing' => $r['listing'],
                'score'   => $r['score'],
                'reasons' => $r['reasons'],
            ]),
        ]);
    }

    private function validated(Request $request, bool $creating = true): array
    {
        $req = $creating ? 'required' : 'sometimes';
        return $request->validate([
            'type'         => [$req, 'string', 'max:20'],
            'title'        => [$req, 'string', 'max:200'],
            'description'  => ['nullable', 'string', 'max:5000'],
            'status'       => ['sometimes', Rule::in(['draft', 'active', 'inactive', 'sold'])],
            'price'        => ['nullable', 'numeric', 'min:0'],
            'incentive_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'price_unit'   => ['nullable', 'string', 'max:20'],
            'currency'     => ['sometimes', 'string', 'max:8'],
            'location'     => ['nullable', 'string', 'max:160'],
            'attributes'   => ['nullable', 'array'],
            'media'        => ['nullable', 'array'],
            'media.*.url'  => ['required_with:media', 'string'],
            'sort_order'   => ['sometimes', 'integer'],
        ]);
    }

    private function find(int $id): Listing
    {
        return Listing::where('id', $id)->where('company_id', auth()->user()->company_id)->firstOrFail();
    }
}
