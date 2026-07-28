<?php

namespace App\Modules\Flow\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Flow\DTOs\CreateFlowNodeDTO;
use App\Modules\Flow\DTOs\ReorderFlowDTO;
use App\Modules\Flow\DTOs\UpdateFlowNodeDTO;
use App\Modules\Flow\Http\Requests\CreateFlowNodeRequest;
use App\Modules\Flow\Http\Requests\ReorderFlowRequest;
use App\Modules\Flow\Http\Requests\UpdateFlowNodeRequest;
use App\Modules\Flow\Http\Resources\FlowNodeResource;
use App\Modules\Flow\Http\Resources\FlowTreeResource;
use App\Modules\Flow\Services\FlowService;
use Illuminate\Http\JsonResponse;

class FlowController extends Controller
{
    public function __construct(
        private readonly FlowService $flowService,
    ) {}

    // ─── GET /flow (full recursive tree) ─────────────────────────────────────
    public function tree(): JsonResponse
    {
        $tree = $this->flowService->tree(auth()->user()->company_id);

        return response()->json([
            'tree' => FlowTreeResource::collection($tree),
        ]);
    }

    // ─── GET /flow/flat (all nodes flat for dropdowns) ───────────────────────
    public function flat(): JsonResponse
    {
        $nodes = $this->flowService->flat(auth()->user()->company_id);

        return response()->json([
            'nodes' => FlowNodeResource::collection($nodes),
        ]);
    }

    // ─── GET /flow/{node} ────────────────────────────────────────────────────
    public function show(int $node): JsonResponse
    {
        $n = $this->flowService->show($node, auth()->user()->company_id);

        return response()->json([
            'node' => new FlowTreeResource($n),
        ]);
    }

    // ─── POST /flow ───────────────────────────────────────────────────────────
    public function store(CreateFlowNodeRequest $request): JsonResponse
    {
        $node = $this->flowService->create(
            companyId: auth()->user()->company_id,
            dto:       CreateFlowNodeDTO::fromRequest($request->validated()),
        );

        return response()->json([
            'message' => 'Flow node created.',
            'node'    => new FlowNodeResource($node),
        ], 201);
    }

    // ─── PUT /flow/{node} ─────────────────────────────────────────────────────
    public function update(UpdateFlowNodeRequest $request, int $node): JsonResponse
    {
        $n = $this->flowService->update(
            id:        $node,
            companyId: auth()->user()->company_id,
            dto:       UpdateFlowNodeDTO::fromRequest($request->validated()),
        );

        return response()->json([
            'message' => 'Flow node updated.',
            'node'    => new FlowNodeResource($n),
        ]);
    }

    // ─── PATCH /flow/{node}/toggle ────────────────────────────────────────────
    public function toggle(int $node): JsonResponse
    {
        $n = $this->flowService->toggle($node, auth()->user()->company_id);

        return response()->json([
            'message'   => $n->is_active ? 'Node activated.' : 'Node deactivated.',
            'is_active' => $n->is_active,
            'node'      => new FlowNodeResource($n),
        ]);
    }

    // ─── DELETE /flow/{node} ──────────────────────────────────────────────────
    public function destroy(int $node): JsonResponse
    {
        $deletedChildren = $this->flowService->delete($node, auth()->user()->company_id);

        return response()->json([
            'message'          => 'Node deleted.',
            'deleted_children' => $deletedChildren,
        ]);
    }

    // ─── POST /flow/reorder ───────────────────────────────────────────────────
    public function reorder(ReorderFlowRequest $request): JsonResponse
    {
        $this->flowService->reorder(
            companyId: auth()->user()->company_id,
            dto:       ReorderFlowDTO::fromRequest($request->validated()),
        );

        return response()->json(['message' => 'Flow reordered.']);
    }

    // ─── POST /flow/duplicate/{node} ──────────────────────────────────────────
    public function duplicate(int $node): JsonResponse
    {
        $copy = $this->flowService->duplicate($node, auth()->user()->company_id);

        return response()->json([
            'message' => 'Node duplicated (starts as inactive).',
            'node'    => new FlowNodeResource($copy),
        ], 201);
    }
}
