<?php
namespace App\Modules\Lead\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\LeadCategory;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\DB;

class LeadCategoryController extends Controller
{
    // GET /lead-categories
    // Supports: ?search=uni&active_only=1&with_count=1
    public function index(Request $request): JsonResponse
    {
        $cid = auth()->user()->company_id;

        $query = LeadCategory::where('company_id', $cid)
            ->when($request->search, fn($q) =>
                $q->where('name', 'like', '%' . $request->search . '%')
            )
            ->when($request->boolean('active_only', true), fn($q) =>
                $q->where('is_active', true)
            )
            ->orderBy('sort_order')
            ->orderBy('name');

        // Return just names array for simple search dropdowns
        if ($request->boolean('names_only')) {
            return response()->json([
                'categories' => $query->pluck('name'),
            ]);
        }

        return response()->json([
            'categories' => $query->withCount('leads')->get(),
        ]);
    }

    // POST /lead-categories
    public function store(Request $request): JsonResponse
    {
        $cid = auth()->user()->company_id;

        $d = $request->validate([
            'name'        => ['required', 'string', 'max:100'],
            'color'       => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'description' => ['nullable', 'string', 'max:500'],
            'sort_order'  => ['nullable', 'integer'],
        ]);

        // Check uniqueness within company
        $exists = LeadCategory::where('company_id', $cid)
            ->where('name', $d['name'])
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => "Category '{$d['name']}' already exists.",
            ], 422);
        }

        $category = LeadCategory::create([
            'company_id'  => $cid,
            'name'        => $d['name'],
            'color'       => $d['color']       ?? '#1D9E75',
            'description' => $d['description'] ?? null,
            'sort_order'  => $d['sort_order']  ?? LeadCategory::where('company_id', $cid)->max('sort_order') + 1,
            'is_active'   => true,
        ]);

        return response()->json(['category' => $category], 201);
    }

    // PUT /lead-categories/{id}
    public function update(Request $request, int $id): JsonResponse
    {
        $category = LeadCategory::where('id', $id)
            ->where('company_id', auth()->user()->company_id)
            ->firstOrFail();

        $d = $request->validate([
            'name'        => ['sometimes', 'string', 'max:100'],
            'color'       => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_active'   => ['sometimes', 'boolean'],
            'sort_order'  => ['nullable', 'integer'],
        ]);

        // Check name uniqueness if changing name
        if (isset($d['name']) && $d['name'] !== $category->name) {
            $exists = LeadCategory::where('company_id', auth()->user()->company_id)
                ->where('name', $d['name'])
                ->where('id', '!=', $id)
                ->exists();
            if ($exists) {
                return response()->json(['message' => "Category '{$d['name']}' already exists."], 422);
            }
        }

        $category->update($d);

        return response()->json(['category' => $category->fresh()->loadCount('leads')]);
    }

    // DELETE /lead-categories/{id}
    public function destroy(int $id): JsonResponse
    {
        $category = LeadCategory::where('id', $id)
            ->where('company_id', auth()->user()->company_id)
            ->firstOrFail();

        $leadCount = $category->leads()->count();
        if ($leadCount > 0) {
            return response()->json([
                'message' => "Cannot delete — {$leadCount} lead(s) use this category. Reassign them first.",
            ], 422);
        }

        $category->delete();
        return response()->json(['message' => 'Category deleted.']);
    }

    // POST /lead-categories/reorder
    public function reorder(Request $request): JsonResponse
    {
        $cid = auth()->user()->company_id;

        $d = $request->validate([
            'order'              => ['required', 'array'],
            'order.*.id'         => ['required', 'integer'],
            'order.*.sort_order' => ['required', 'integer'],
        ]);

        DB::transaction(function () use ($d, $cid) {
            foreach ($d['order'] as $item) {
                LeadCategory::where('id', $item['id'])
                    ->where('company_id', $cid)
                    ->update(['sort_order' => $item['sort_order']]);
            }
        });

        return response()->json(['message' => 'Reordered.']);
    }

    // POST /lead-categories/sync-from-nodes
    // Scan all flow nodes for lead_category strings and create missing categories
    public function syncFromNodes(): JsonResponse
    {
        $cid = auth()->user()->company_id;

        $nodeCategories = \App\Models\FlowNode::where('company_id', $cid)
            ->whereNotNull('lead_category')
            ->where('lead_category', '!=', '')
            ->pluck('lead_category')
            ->unique()
            ->values();

        $created = 0;
        foreach ($nodeCategories as $catName) {
            $exists = LeadCategory::where('company_id', $cid)->where('name', $catName)->exists();
            if (!$exists) {
                LeadCategory::create([
                    'company_id' => $cid,
                    'name'       => $catName,
                    'color'      => '#1D9E75',
                    'is_active'  => true,
                    'sort_order' => LeadCategory::where('company_id', $cid)->max('sort_order') + 1,
                ]);
                $created++;
            }
        }

        return response()->json([
            'message'   => "Synced. {$created} new categories created from flow nodes.",
            'synced'    => $nodeCategories->count(),
            'created'   => $created,
        ]);
    }
}
