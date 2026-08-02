<?php

namespace App\Modules\Flow\Http\Controllers;

use App\Models\FlowBuilder;
use App\Models\FlowNode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class FlowBuilderController extends Controller
{
    // ─── GET /flow-builders ───────────────────────────────────────────────────
    public function index(): JsonResponse
    {
        $builders = FlowBuilder::where('company_id', auth()->user()->company_id)
            ->withCount('nodes')
            ->orderByDesc('is_active')
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['builders' => $builders]);
    }

    // ─── POST /flow-builders ──────────────────────────────────────────────────
    public function store(Request $request): JsonResponse
    {
        $companyId = auth()->user()->company_id;

        $d = $request->validate([
            'name'             => ['required', 'string', 'max:100'],
            'description'      => ['nullable', 'string', 'max:255'],
            'trigger_type'     => ['required', Rule::in(['default', 'keyword', 'season'])],
            'trigger_keywords' => ['nullable', 'array'],
            'trigger_keywords.*' => ['string', 'max:60'],
            'active_from'      => ['nullable', 'date', 'required_if:trigger_type,season'],
            'active_until'     => ['nullable', 'date', 'after:active_from', 'required_if:trigger_type,season'],
        ]);

        // Validate keyword flow has at least one keyword
        if ($d['trigger_type'] === 'keyword' && empty($d['trigger_keywords'])) {
            return response()->json(['message' => 'Keyword flow requires at least one trigger keyword.'], 422);
        }

        // Only one default flow allowed per company
        if ($d['trigger_type'] === 'default') {
            $existingDefault = FlowBuilder::where('company_id', $companyId)
                ->where('trigger_type', 'default')
                ->count();

            if ($existingDefault >= 3) {
                return response()->json(['message' => 'Maximum 3 default flow builders allowed. Deactivate or delete one first.'], 422);
            }
        }

        $builder = FlowBuilder::create([
            'company_id'       => $companyId,
            'created_by'       => auth()->id(),
            'name'             => $d['name'],
            'description'      => $d['description'] ?? null,
            'trigger_type'     => $d['trigger_type'],
            'trigger_keywords' => json_encode($d['trigger_keywords'] ?? []),
            'active_from'      => $d['active_from']  ?? null,
            'active_until'     => $d['active_until'] ?? null,
            'is_active'        => false, // always starts inactive — must explicitly activate
        ]);

        return response()->json(['builder' => $builder->loadCount('nodes')], 201);
    }

    // ─── GET /flow-builders/{id} ──────────────────────────────────────────────
    public function show(int $id): JsonResponse
    {
        $builder = FlowBuilder::where('id', $id)
            ->where('company_id', auth()->user()->company_id)
            ->withCount('nodes')
            ->firstOrFail();

        // Load full node tree
        $nodes = FlowNode::where('company_id', auth()->user()->company_id)
            ->where('flow_builder_id', $id)
            ->orderBy('sort_order')
            ->get();

        return response()->json([
            'builder' => $builder,
            'nodes'   => $nodes,
        ]);
    }

    // ─── PUT /flow-builders/{id} ──────────────────────────────────────────────
    public function update(Request $request, int $id): JsonResponse
    {
        $builder = FlowBuilder::where('id', $id)
            ->where('company_id', auth()->user()->company_id)
            ->firstOrFail();

        $d = $request->validate([
            'name'             => ['sometimes', 'string', 'max:100'],
            'description'      => ['nullable', 'string', 'max:255'],
            'trigger_type'     => ['sometimes', Rule::in(['default', 'keyword', 'season'])],
            'trigger_keywords' => ['nullable', 'array'],
            'trigger_keywords.*' => ['string', 'max:60'],
            'active_from'      => ['nullable', 'date'],
            'active_until'     => ['nullable', 'date', 'after:active_from'],
        ]);

        $builder->update([
            'name'             => $d['name']             ?? $builder->name,
            'description'      => $d['description']      ?? $builder->description,
            'trigger_type'     => $d['trigger_type']     ?? $builder->trigger_type,
            'trigger_keywords' => isset($d['trigger_keywords'])
                ? json_encode($d['trigger_keywords'])
                : $builder->trigger_keywords,
            'active_from'      => $d['active_from']      ?? $builder->active_from,
            'active_until'     => $d['active_until']     ?? $builder->active_until,
        ]);

        return response()->json(['builder' => $builder->fresh()->loadCount('nodes')]);
    }

    // ─── POST /flow-builders/{id}/activate ───────────────────────────────────
    // Only one builder of each trigger_type can be active at a time
    public function activate(int $id): JsonResponse
    {
        $companyId = auth()->user()->company_id;

        $builder = FlowBuilder::where('id', $id)
            ->where('company_id', $companyId)
            ->firstOrFail();

        // Confirm builder has at least one active root node before allowing activation
        $rootNodeCount = FlowNode::where('company_id', $companyId)
            ->where('flow_builder_id', $id)
            ->whereNull('parent_id')
            ->where('is_active', true)
            ->count();

        if ($rootNodeCount === 0) {
            return response()->json([
                'message' => 'Cannot activate — this flow builder has no active root node. Add at least one root node first.',
            ], 422);
        }

        DB::transaction(function () use ($builder, $companyId) {
            // Deactivate all builders of the same trigger_type
            FlowBuilder::where('company_id', $companyId)
                ->where('trigger_type', $builder->trigger_type)
                ->where('id', '!=', $builder->id)
                ->update(['is_active' => false]);

            // Activate this one
            $builder->update(['is_active' => true]);
        });

        Log::info("Flow builder activated: id={$builder->id} type={$builder->trigger_type} company={$companyId}");

        return response()->json([
            'message' => "Flow builder '{$builder->name}' is now active.",
            'builder' => $builder->fresh()->loadCount('nodes'),
        ]);
    }

    // ─── POST /flow-builders/{id}/deactivate ─────────────────────────────────
    public function deactivate(int $id): JsonResponse
    {
        $builder = FlowBuilder::where('id', $id)
            ->where('company_id', auth()->user()->company_id)
            ->firstOrFail();

        $builder->update(['is_active' => false]);

        return response()->json([
            'message' => "Flow builder '{$builder->name}' deactivated.",
            'builder' => $builder->fresh(),
        ]);
    }

    // ─── DELETE /flow-builders/{id} ──────────────────────────────────────────
    public function destroy(int $id): JsonResponse
    {
        $builder = FlowBuilder::where('id', $id)
            ->where('company_id', auth()->user()->company_id)
            ->firstOrFail();

        // Cannot delete an active builder
        if ($builder->is_active) {
            return response()->json([
                'message' => 'Cannot delete an active flow builder. Deactivate it first.',
            ], 422);
        }

        DB::transaction(function () use ($builder) {
            // Delete all nodes in this builder first
            FlowNode::where('flow_builder_id', $builder->id)->delete();
            $builder->delete();
        });

        return response()->json(['message' => 'Flow builder and all its nodes deleted.']);
    }

    // ─── POST /flow-builders/{id}/duplicate ──────────────────────────────────
    // Clone a builder and all its nodes (useful for seasonal flows)
    public function duplicate(int $id): JsonResponse
    {
        $companyId = auth()->user()->company_id;

        $original = FlowBuilder::where('id', $id)
            ->where('company_id', $companyId)
            ->firstOrFail();

        $nodes = FlowNode::where('flow_builder_id', $id)
            ->where('company_id', $companyId)
            ->orderBy('id')
            ->get();

        DB::transaction(function () use ($original, $nodes, $companyId, &$newBuilder) {
            // Clone builder
            $newBuilder = FlowBuilder::create([
                'company_id'       => $companyId,
                'created_by'       => auth()->id(),
                'name'             => $original->name . ' (Copy)',
                'description'      => $original->description,
                'trigger_type'     => $original->trigger_type,
                'trigger_keywords' => $original->trigger_keywords,
                'active_from'      => null,
                'active_until'     => null,
                'is_active'        => false,
            ]);

            // Clone nodes — map old IDs to new IDs to preserve parent relationships
            $idMap = [];

            foreach ($nodes as $node) {
                $newNode = FlowNode::create([
                    'company_id'      => $companyId,
                    'flow_builder_id' => $newBuilder->id,
                    'parent_id'       => null, // set after all nodes created
                    'title'           => $node->title,
                    'message'         => $node->message,
                    'type'            => $node->type,
                    'reply_id'        => $node->reply_id . '_copy',
                    'lead_category'   => $node->lead_category,
                    'sort_order'      => $node->sort_order,
                    'is_active'       => $node->is_active,
                ]);
                $idMap[$node->id] = $newNode->id;
            }

            // Now fix parent_id references using the ID map
            foreach ($nodes as $node) {
                if ($node->parent_id && isset($idMap[$node->parent_id])) {
                    FlowNode::where('id', $idMap[$node->id])
                        ->update(['parent_id' => $idMap[$node->parent_id]]);
                }
            }
        });

        return response()->json([
            'message' => "Flow builder duplicated as '{$newBuilder->name}'.",
            'builder' => $newBuilder->loadCount('nodes'),
        ], 201);
    }

    public function activate(int $id): JsonResponse
    {
        $cid = auth()->user()->company_id;

        // Deactivate all others
        FlowBuilder::where('company_id', $cid)->update(['is_active' => false]);

        // Activate selected
        $builder = FlowBuilder::where('id', $id)->where('company_id', $cid)->firstOrFail();
        $builder->update(['is_active' => true]);

        return response()->json(['message' => "Flow '{$builder->name}' is now active."]);
    }
}
