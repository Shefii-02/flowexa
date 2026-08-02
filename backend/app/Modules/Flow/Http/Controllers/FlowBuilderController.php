<?php

namespace App\Modules\Flow\Http\Controllers;

use App\Models\FlowBuilder;
use App\Models\FlowNode;
use App\Modules\Flow\Http\Resources\FlowBuilderResource;
use App\Modules\Flow\Http\Resources\FlowNodeResource;
use App\Modules\Flow\Services\FlowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class FlowBuilderController extends Controller
{
    // GET /flow-builders
    public function index(): JsonResponse
    {
        $builders = FlowBuilder::where('company_id', auth()->user()->company_id)
            ->withCount('nodes')
            ->orderByDesc('is_active')
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['builders' => $builders]);
    }

    // GET /flow-builders/{id}  — builder + full nested node tree
    public function show(int $id): JsonResponse
    {
        $cid     = auth()->user()->company_id;
        $builder = FlowBuilder::where('id', $id)
            ->where('company_id', $cid)
            ->withCount('nodes')
            ->firstOrFail();

        $nodes = $this->buildTree($cid, $id);

        return response()->json(['builder' => $builder, 'nodes' => $nodes]);
    }

    // POST /flow-builders
    public function store(Request $request): JsonResponse
    {
        $cid = auth()->user()->company_id;

        $d = $request->validate([
            'name'               => ['required', 'string', 'max:100'],
            'description'        => ['nullable', 'string', 'max:255'],
            'trigger_type'       => ['required', Rule::in(['default', 'keyword', 'season'])],
            'trigger_keywords'   => ['nullable', 'array'],
            'trigger_keywords.*' => ['string', 'max:60'],
            'active_from'        => ['nullable', 'date'],
            'active_until'       => ['nullable', 'date', 'after:active_from'],
        ]);

        if ($d['trigger_type'] === 'keyword' && empty($d['trigger_keywords'])) {
            return response()->json(['message' => 'Keyword flow needs at least one keyword.'], 422);
        }

        if ($d['trigger_type'] === 'season' && (empty($d['active_from']) || empty($d['active_until']))) {
            return response()->json(['message' => 'Season flow needs active_from and active_until.'], 422);
        }

        $builder = FlowBuilder::create([
            'company_id'       => $cid,
            'created_by'       => auth()->id(),
            'name'             => $d['name'],
            'description'      => $d['description']      ?? null,
            'trigger_type'     => $d['trigger_type'],
            'trigger_keywords' => json_encode($d['trigger_keywords'] ?? []),
            'active_from'      => $d['active_from']      ?? null,
            'active_until'     => $d['active_until']     ?? null,
            'is_active'        => false,
        ]);

        return response()->json(['builder' => $builder->loadCount('nodes')], 201);
    }

    // PUT /flow-builders/{id}
    public function update(Request $request, int $id): JsonResponse
    {
        $builder = FlowBuilder::where('id', $id)
            ->where('company_id', auth()->user()->company_id)
            ->firstOrFail();

        $d = $request->validate([
            'name'               => ['sometimes', 'string', 'max:100'],
            'description'        => ['nullable', 'string', 'max:255'],
            'trigger_type'       => ['sometimes', Rule::in(['default', 'keyword', 'season'])],
            'trigger_keywords'   => ['nullable', 'array'],
            'trigger_keywords.*' => ['string', 'max:60'],
            'active_from'        => ['nullable', 'date'],
            'active_until'       => ['nullable', 'date', 'after:active_from'],
        ]);

        $builder->update([
            'name'             => $d['name']           ?? $builder->name,
            'description'      => $d['description']    ?? $builder->description,
            'trigger_type'     => $d['trigger_type']   ?? $builder->trigger_type,
            'trigger_keywords' => isset($d['trigger_keywords'])
                ? json_encode($d['trigger_keywords'])
                : $builder->trigger_keywords,
            'active_from'      => $d['active_from']    ?? $builder->active_from,
            'active_until'     => $d['active_until']   ?? $builder->active_until,
        ]);

        return response()->json(['builder' => $builder->fresh()->loadCount('nodes')]);
    }

    // POST /flow-builders/{id}/activate
    // Only ONE builder of each trigger_type active at a time
    public function activate(int $id): JsonResponse
    {
        $cid     = auth()->user()->company_id;
        $builder = FlowBuilder::where('id', $id)
            ->where('company_id', $cid)
            ->firstOrFail();

        // Must have an active root node before activating
        // $hasRoot = FlowNode::where('company_id', $cid)
        //     ->where('flow_builder_id', $id)
        //     ->whereNull('parent_id')
        //     ->where('is_active', true)
        //     ->exists();

        // if (!$hasRoot) {
        //     return response()->json([
        //         'message' => 'Cannot activate — add at least one active root node first.',
        //     ], 422);
        // }

        DB::transaction(function () use ($builder, $cid) {
            // Deactivate all other builders of same trigger_type
            // FlowBuilder::where('company_id', $cid)
            //     ->where('trigger_type', $builder->trigger_type)
            //     ->where('id', '!=', $builder->id)
            //     ->update(['is_active' => false]);

            $builder->update(['is_active' => true]);
        });

        return response()->json([
            'message' => "'{$builder->name}' is now the active {$builder->trigger_type} flow.",
            'builder' => $builder->fresh()->loadCount('nodes'),
        ]);
    }

    // POST /flow-builders/{id}/deactivate
    public function deactivate(int $id): JsonResponse
    {
        $builder = FlowBuilder::where('id', $id)
            ->where('company_id', auth()->user()->company_id)
            ->firstOrFail();

        $builder->update(['is_active' => false]);

        return response()->json([
            'message' => "'{$builder->name}' deactivated.",
            'builder' => $builder->fresh(),
        ]);
    }

    // DELETE /flow-builders/{id}
    public function destroy(int $id): JsonResponse
    {
        $builder = FlowBuilder::where('id', $id)
            ->where('company_id', auth()->user()->company_id)
            ->firstOrFail();

        if ($builder->is_active) {
            return response()->json(['message' => 'Deactivate before deleting.'], 422);
        }

        DB::transaction(function () use ($builder) {
            FlowNode::where('flow_builder_id', $builder->id)->delete();
            $builder->delete();
        });

        return response()->json(['message' => 'Builder and all its nodes deleted.']);
    }

    // ── Recursive tree builder ────────────────────────────────────────────
    private function buildTree(int $cid, int $builderId, ?int $parentId = null): array
    {
        return FlowNode::where('company_id', $cid)
            ->where('flow_builder_id', $builderId)
            ->where('parent_id', $parentId)
            ->orderBy('sort_order')
            ->get()
            ->map(fn($n) => [
                ...$n->toArray(),
                'children' => $this->buildTree($cid, $builderId, $n->id),
            ])
            ->toArray();
    }
}
