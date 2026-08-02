<?php

namespace App\Modules\Flow\Http\Controllers;

use App\Models\FlowBuilder;
use App\Models\FlowNode;
use App\Modules\Flow\Http\Resources\FlowNodeResource;
use App\Modules\Flow\Services\FlowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class FlowNodeController extends Controller
{
    // Verify builder belongs to company
    private function builder(int $bid): FlowBuilder
    {
        return FlowBuilder::where('id', $bid)
            ->where('company_id', auth()->user()->company_id)
            ->firstOrFail();
    }

    // GET /flow-builders/{bid}/nodes — flat list
    public function index(int $bid): JsonResponse
    {
        $this->builder($bid);

        $nodes = FlowNode::where('flow_builder_id', $bid)
            ->where('company_id', auth()->user()->company_id)
            ->orderByRaw('ISNULL(parent_id) DESC')
            ->orderBy('sort_order')
            ->get();

        return response()->json(['nodes' => $nodes]);
    }

    // POST /flow-builders/{bid}/nodes — create node
    public function store(Request $request, int $bid): JsonResponse
    {
        $this->builder($bid);
        $cid = auth()->user()->company_id;

        $d = $request->validate([
            'parent_id'        => ['nullable', 'integer'],
            'title'            => ['required', 'string', 'max:100'],
            'message'          => ['required', 'string', 'max:4096'],

            // Multi-message: array of sequential messages sent one by one
            'multi_messages'            => ['nullable', 'array', 'max:10'],
            'multi_messages.*.type'     => [
                'required_with:multi_messages',
                Rule::in(['text', 'image', 'video', 'document', 'audio', 'location'])
            ],
            'multi_messages.*.content'  => ['nullable', 'string', 'max:4096'],
            'multi_messages.*.url'      => ['nullable', 'url'],
            'multi_messages.*.caption'  => ['nullable', 'string', 'max:255'],
            'multi_messages.*.filename' => ['nullable', 'string', 'max:150'],
            'multi_messages.*.lat'      => ['nullable', 'numeric'],
            'multi_messages.*.lng'      => ['nullable', 'numeric'],
            'multi_messages.*.name'     => ['nullable', 'string'],
            'multi_messages.*.address'  => ['nullable', 'string'],

            'type'             => ['required', Rule::in(['text', 'button', 'list', 'image', 'video', 'document', 'audio', 'location'])],
            'reply_id'         => ['required', 'string', 'max:200'],
            'lead_category'    => ['nullable', 'string', 'max:100'],
            'sort_order'       => ['nullable', 'integer'],

            // Single media (header image/video/document for button nodes)
            'media_type'       => ['nullable', Rule::in(['image', 'video', 'document', 'audio', 'location'])],
            'media_url'        => ['nullable', 'url'],
            'media_caption'    => ['nullable', 'string', 'max:255'],
            'media_filename'   => ['nullable', 'string', 'max:150'],
            'location_lat'     => ['nullable', 'numeric'],
            'location_lng'     => ['nullable', 'numeric'],
            'location_name'    => ['nullable', 'string', 'max:150'],
            'location_address' => ['nullable', 'string', 'max:255'],
        ]);

        // reply_id must be unique within this builder
        if (FlowNode::where('flow_builder_id', $bid)->where('reply_id', $d['reply_id'])->exists()) {
            return response()->json(['message' => "reply_id '{$d['reply_id']}' already used in this builder."], 422);
        }

        // parent_id must belong to same builder
        if (!empty($d['parent_id'])) {
            $ok = FlowNode::where('id', $d['parent_id'])->where('flow_builder_id', $bid)->exists();
            if (!$ok) return response()->json(['message' => 'Parent node not found in this builder.'], 422);
        }

        $node = FlowNode::create([
            'company_id'       => $cid,
            'flow_builder_id'  => $bid,
            'parent_id'        => $d['parent_id']      ?? null,
            'title'            => $d['title'],
            'message'          => $d['message'],
            'multi_messages'   => $d['multi_messages']  ?? null,
            'type'             => $d['type'],
            'reply_id'         => $d['reply_id'],
            'lead_category'    => $d['lead_category']   ?? null,
            'sort_order'       => $d['sort_order']      ?? 0,
            'is_active'        => true,
            'media_type'       => $d['media_type']      ?? null,
            'media_url'        => $d['media_url']       ?? null,
            'media_caption'    => $d['media_caption']   ?? null,
            'media_filename'   => $d['media_filename']  ?? null,
            'location_lat'     => $d['location_lat']    ?? null,
            'location_lng'     => $d['location_lng']    ?? null,
            'location_name'    => $d['location_name']   ?? null,
            'location_address' => $d['location_address'] ?? null,
        ]);

        return response()->json(['node' => $node->load('children')], 201);
    }

    // PUT /flow-builders/{bid}/nodes/{id}
    public function update(Request $request, int $bid, int $id): JsonResponse
    {
        $this->builder($bid);

        $node = FlowNode::where('id', $id)
            ->where('flow_builder_id', $bid)
            ->where('company_id', auth()->user()->company_id)
            ->firstOrFail();

        $d = $request->validate([
            'title'            => ['sometimes', 'string', 'max:100'],
            'message'          => ['sometimes', 'string', 'max:4096'],
            'multi_messages'   => ['nullable', 'array', 'max:10'],
            'multi_messages.*.type'     => [
                'required_with:multi_messages',
                Rule::in(['text', 'image', 'video', 'document', 'audio', 'location'])
            ],
            'multi_messages.*.content'  => ['nullable', 'string'],
            'multi_messages.*.url'      => ['nullable', 'url'],
            'multi_messages.*.caption'  => ['nullable', 'string'],
            'multi_messages.*.filename' => ['nullable', 'string'],
            'multi_messages.*.lat'      => ['nullable', 'numeric'],
            'multi_messages.*.lng'      => ['nullable', 'numeric'],
            'multi_messages.*.name'     => ['nullable', 'string'],
            'multi_messages.*.address'  => ['nullable', 'string'],
            'type'             => ['sometimes', Rule::in(['text', 'button', 'list', 'image', 'video', 'document', 'audio', 'location'])],
            'lead_category'    => ['nullable', 'string', 'max:100'],
            'sort_order'       => ['nullable', 'integer'],
            'media_type'       => ['nullable', Rule::in(['image', 'video', 'document', 'audio', 'location'])],
            'media_url'        => ['nullable', 'url'],
            'media_caption'    => ['nullable', 'string', 'max:255'],
            'media_filename'   => ['nullable', 'string', 'max:150'],
            'location_lat'     => ['nullable', 'numeric'],
            'location_lng'     => ['nullable', 'numeric'],
            'location_name'    => ['nullable', 'string', 'max:150'],
            'location_address' => ['nullable', 'string', 'max:255'],
        ]);

        $node->update($d);

        return response()->json(['node' => $node->fresh()->load('children')]);
    }

    // DELETE /flow-builders/{bid}/nodes/{id}
    public function destroy(int $bid, int $id): JsonResponse
    {
        $this->builder($bid);

        $node = FlowNode::where('id', $id)
            ->where('flow_builder_id', $bid)
            ->where('company_id', auth()->user()->company_id)
            ->firstOrFail();

        $childCount = FlowNode::where('parent_id', $id)->count();
        if ($childCount > 0) {
            return response()->json([
                'message' => "Delete {$childCount} child node(s) first before deleting this node.",
            ], 422);
        }

        $node->delete();
        return response()->json(['message' => 'Node deleted.']);
    }

    // POST /flow-builders/{bid}/nodes/{id}/toggle — activate / deactivate
    public function toggle(int $bid, int $id): JsonResponse
    {
        $this->builder($bid);

        $node = FlowNode::where('id', $id)
            ->where('flow_builder_id', $bid)
            ->where('company_id', auth()->user()->company_id)
            ->firstOrFail();

        $node->update(['is_active' => !$node->is_active]);

        return response()->json([
            'message' => 'Node ' . ($node->is_active ? 'activated' : 'deactivated') . '.',
            'node'    => $node->fresh(),
        ]);
    }

    // POST /flow-builders/{bid}/nodes/reorder — drag-drop sort
    public function reorder(Request $request, int $bid): JsonResponse
    {
        $this->builder($bid);
        $cid = auth()->user()->company_id;

        $d = $request->validate([
            'order'              => ['required', 'array'],
            'order.*.id'         => ['required', 'integer'],
            'order.*.sort_order' => ['required', 'integer'],
        ]);

        DB::transaction(function () use ($d, $bid, $cid) {
            foreach ($d['order'] as $item) {
                FlowNode::where('id', $item['id'])
                    ->where('flow_builder_id', $bid)
                    ->where('company_id', $cid)
                    ->update(['sort_order' => $item['sort_order']]);
            }
        });

        return response()->json(['message' => 'Nodes reordered.']);
    }

    // GET /api/flow-builders/{builder}/nodes/check-reply-id
    public function checkReplyId(FlowBuilder $builder, Request $request): JsonResponse
    {
        $request->validate(['reply_id' => 'required|string|max:200']);

        $exists = FlowNode::where('company_id', $request->user()->company_id)
            ->where('flow_builder_id', $builder->id)
            ->where('reply_id', $request->reply_id)
            ->when($request->exclude_id, fn($q, $id) => $q->where('id', '!=', $id))
            ->exists();

        return response()->json(['exists' => $exists]);
    }

    // POST /api/flow-nodes/upload-media
    public function uploadMedia(Request $request): JsonResponse
    {
        $request->validate(['file' => 'required|file|max:102400']); // 100MB hard cap

        $company = $request->user()->company;
        $file    = $request->file('file');
        $size    = $file->getSize();

        $limit = $company->storage_limit_bytes;
        if ($company->storage_used_bytes + $size > $limit) {
            return response()->json([
                'message' => 'Storage limit reached. Free up space or upgrade your plan to upload more media.',
            ], 422);
        }

        $path = $file->store("flow-media/{$company->id}", 'public');
        $url  = Storage::disk('public')->url($path);

        $asset = \App\Models\MediaAsset::create([
            'company_id'    => $company->id,
            'disk'          => 'public',
            'path'          => $path,
            'url'           => $url,
            'mime_type'     => $file->getClientMimeType(),
            'original_name' => $file->getClientOriginalName(),
            'size'          => $size,
        ]);

        $company->increment('storage_used_bytes', $size);

        return response()->json([
            'url' => $url,
            'size' => $size,
            'mime_type' => $asset->mime_type,
            'asset_id' => $asset->id,
        ]);
    }
}
