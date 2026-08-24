<?php

namespace App\Modules\Flow\Http\Controllers;

use App\Models\FlowBuilder;
use App\Models\FlowNode;
use App\Models\MediaAsset;
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

    private function sharedRules(): array
    {
        return [
            'multi_messages'             => ['nullable', 'array', 'max:10'],
            'multi_messages.*.type'      => ['required_with:multi_messages', Rule::in(['text', 'image', 'video', 'document', 'audio', 'location'])],
            'multi_messages.*.content'   => ['nullable', 'string', 'max:4096'],
            'multi_messages.*.url'       => ['nullable', 'url'],
            'multi_messages.*.caption'   => ['nullable', 'string', 'max:255'],
            'multi_messages.*.filename'  => ['nullable', 'string', 'max:150'],
            'multi_messages.*.size'      => ['nullable', 'integer'],
            'multi_messages.*.mime_type' => ['nullable', 'string', 'max:150'],
            'multi_messages.*.lat'       => ['nullable', 'numeric'],
            'multi_messages.*.lng'       => ['nullable', 'numeric'],
            'multi_messages.*.name'      => ['nullable', 'string'],
            'multi_messages.*.address'   => ['nullable', 'string'],
            'is_dead_end'                => ['nullable', 'boolean'],

            'lead_category'    => ['nullable', 'string', 'max:100'],
            'sort_order'       => ['nullable', 'integer'],
            'is_active'        => ['nullable', 'boolean'],

            'media_type'       => ['nullable', Rule::in(['image', 'video', 'document', 'audio', 'location'])],
            'media_url'        => ['nullable', 'url'],
            'media_caption'    => ['nullable', 'string', 'max:255'],
            'media_filename'   => ['nullable', 'string', 'max:150'],
            'location_lat'     => ['nullable', 'numeric'],
            'location_lng'     => ['nullable', 'numeric'],
            'location_name'    => ['nullable', 'string', 'max:150'],
            'location_address' => ['nullable', 'string', 'max:255'],

            // Dynamic node (options loaded from an external API at runtime)
            'is_dynamic'                 => ['nullable', 'boolean'],
            'dynamic_api_url'            => ['nullable', 'required_if:is_dynamic,true', 'url', 'max:500'],
            'dynamic_api_method'         => ['nullable', Rule::in(['GET', 'POST'])],
            'dynamic_api_headers'        => ['nullable', 'string'],
            'dynamic_label_field'        => ['nullable', 'string', 'max:100'],
            'dynamic_value_field'        => ['nullable', 'string', 'max:100'],
            'dynamic_description_field'  => ['nullable', 'string', 'max:100'],
            'dynamic_image_field'        => ['nullable', 'string', 'max:100'],
            'dynamic_subtitle_field'     => ['nullable', 'string', 'max:100'],
        ];
    }

    // If the lead_category name typed in doesn't match an existing LeadCategory row for
    // this company, create one silently instead of leaving the node pointing at a name
    // that doesn't exist anywhere else in the system (lead lists, reporting filters, etc).
    // Mirrors LeadCategoryController::store()'s defaults, but never blocks the node save —
    // "skip if it already exists, create it if not", not an error on duplicates.
    private function ensureLeadCategoryExists(int $companyId, ?string $name): void
    {
        $name = trim((string) $name);
        if ($name === '') return;

        $exists = \App\Models\LeadCategory::where('company_id', $companyId)
            ->where('name', $name)
            ->exists();

        if ($exists) return;

        \App\Models\LeadCategory::create([
            'company_id'  => $companyId,
            'name'        => $name,
            'color'       => '#1D9E75',
            'description' => null,
            'sort_order'  => (\App\Models\LeadCategory::where('company_id', $companyId)->max('sort_order') ?? 0) + 1,
            'is_active'   => true,
        ]);
    }

    // Blocks whose type needs media/location must actually have it — url:null slips
    // past `nullable|url` silently otherwise, which is how empty uploads got saved.
    private function assertMultiMessagesComplete(array $messages): ?JsonResponse
    {
        foreach ($messages as $i => $m) {
            if (in_array($m['type'], ['image', 'video', 'document', 'audio']) && empty($m['url'])) {
                return response()->json(['message' => "Message block #" . ($i + 1) . " ({$m['type']}) is missing a media URL — upload the file first."], 422);
            }
            if ($m['type'] === 'location' && (empty($m['lat']) || empty($m['lng']))) {
                return response()->json(['message' => "Message block #" . ($i + 1) . " (location) is missing lat/lng."], 422);
            }
        }
        return null;
    }

    // Backfill flow_node_id on media_assets uploaded for this node's blocks — covers
    // the "create" path where files were uploaded before the node existed.
    private function linkNodeMediaAssets(FlowNode $node): void
    {
        $urls = collect($node->multi_messages ?? [])->pluck('url')->filter()->values();
        if ($node->media_url) $urls->push($node->media_url);
        if ($urls->isEmpty()) return;

        MediaAsset::where('company_id', $node->company_id)
            ->whereIn('url', $urls->all())
            ->update(['flow_node_id' => $node->id]);
    }

    // Delete every media_assets row (+ file + credit storage back) linked to this node
    private function deleteNodeMediaAssets(FlowNode $node): void
    {
        $assets = MediaAsset::where('flow_node_id', $node->id)->get();
        if ($assets->isEmpty()) return;

        foreach ($assets as $asset) {
            Storage::disk($asset->disk)->delete($asset->path);
        }

        auth()->user()->company()->decrement('storage_used_bytes', $assets->sum('size'));
        MediaAsset::whereIn('id', $assets->pluck('id'))->delete();
    }

    // POST /flow-builders/{bid}/nodes — create node
    public function store(Request $request, int $bid): JsonResponse
    {
        $this->builder($bid);
        $cid = auth()->user()->company_id;

        $d = $request->validate([
            'parent_id'   => ['nullable', 'integer'],
            'title'       => ['required', 'string', 'max:100'],
            'message'     => ['nullable', 'string', 'max:4096'],
            'type'        => ['required', Rule::in(['text', 'button', 'list', 'image', 'video', 'document', 'audio', 'location', 'survey', 'template'])],
            'reply_id'    => [
                'required',
                'string',
                'max:200'
            ],
            'redirect_to_reply_id' => ['nullable', 'string', 'max:200'],
            ...$this->sharedRules(),
        ]);

        if ($err = $this->assertMultiMessagesComplete($d['multi_messages'] ?? [])) return $err;

        if (FlowNode::where('flow_builder_id', $bid)->where('reply_id', $d['reply_id'])->exists()) {
            return response()->json(['message' => "reply_id '{$d['reply_id']}' already used in this builder."], 422);
        }

        if (!empty($d['parent_id'])) {
            $ok = FlowNode::where('id', $d['parent_id'])->where('flow_builder_id', $bid)->exists();
            if (!$ok) return response()->json(['message' => 'Parent node not found in this builder.'], 422);
        }

        // Auto-create the LeadCategory if the typed name doesn't exist yet for this
        // company — never blocks node creation, just keeps the category list in sync.
        $this->ensureLeadCategoryExists($cid, $d['lead_category'] ?? null);

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
            'sort_order'       => $d['sort_order']       ?? 0,
            'is_active'        => $d['is_active']        ?? true, // was hardcoded true before, ignoring the form's toggle
            'media_type'       => $d['media_type']       ?? null,
            'media_url'        => $d['media_url']        ?? null,
            'media_caption'    => $d['media_caption']    ?? null,
            'media_filename'   => $d['media_filename']   ?? null,
            'location_lat'     => $d['location_lat']     ?? null,
            'location_lng'     => $d['location_lng']     ?? null,
            'location_name'    => $d['location_name']    ?? null,
            'location_address' => $d['location_address'] ?? null,
            'is_dead_end'      => $d['is_dead_end'] ?? false,
            'redirect_to_reply_id' => $d['redirect_to_reply_id'] ?? null,

            'is_dynamic'                => $d['is_dynamic']                ?? false,
            'dynamic_api_url'           => $d['dynamic_api_url']           ?? null,
            'dynamic_api_method'        => $d['dynamic_api_method']        ?? 'GET',
            'dynamic_api_headers'       => $d['dynamic_api_headers']       ?? null,
            'dynamic_label_field'       => $d['dynamic_label_field']       ?? 'name',
            'dynamic_value_field'       => $d['dynamic_value_field']       ?? 'id',
            'dynamic_description_field' => $d['dynamic_description_field'] ?? null,
            'dynamic_image_field'       => $d['dynamic_image_field']       ?? null,
            'dynamic_subtitle_field'    => $d['dynamic_subtitle_field']    ?? null,
        ]);

        $this->linkNodeMediaAssets($node);

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
            'title'    => ['sometimes', 'string', 'max:100'],
            'message'  => ['nullable', 'string', 'max:4096'],
            'type'     => ['sometimes', Rule::in(['text', 'button', 'list', 'image', 'video', 'document', 'audio', 'location', 'survey', 'template'])],
            'parent_id'   => ['nullable', 'integer'],
            'reply_id'    => ['required', 'string', 'max:200'],
            'redirect_to_reply_id' => ['nullable', 'string', 'max:200'],
            ...$this->sharedRules(),
        ]);

        if (array_key_exists('multi_messages', $d) && $err = $this->assertMultiMessagesComplete($d['multi_messages'] ?? [])) {
            return $err;
        }

        if (array_key_exists('lead_category', $d)) {
            $this->ensureLeadCategoryExists($node->company_id, $d['lead_category']);
        }


        if (array_key_exists('parent_id', $d) && $d['parent_id'] !== null) {
            // Prevent circular reference — node cannot become its own descendant
            $this->assertNotCircular($node->id, $d['parent_id']);

            $parentOk = FlowNode::where('id', $d['parent_id'])
                ->where('flow_builder_id', $bid)
                ->exists();
            if (!$parentOk) {
                return response()->json(['message' => 'Parent node not in this builder.'], 422);
            }
        }


        $node->update($d);
        $node = $node->fresh();

        $this->linkNodeMediaAssets($node);

        return response()->json(['node' => $node->load('children')]);
    }


    private function assertNotCircular(int $nodeId, int $newParentId): void
    {
        // Walk up the tree from newParentId — if we ever reach nodeId, it's circular
        $current = $newParentId;
        $visited = [];
        while ($current !== null) {
            if ($current === $nodeId) {
                throw new \InvalidArgumentException("Cannot set parent — circular reference detected.");
            }
            if (in_array($current, $visited)) break; // safety
            $visited[] = $current;
            $parent = FlowNode::find($current);
            $current = $parent?->parent_id;
        }
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

        $this->deleteNodeMediaAssets($node);

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

    // GET /flow-builders/{builder}/nodes/check-reply-id
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

    // POST /flow-builders/{bid}/nodes/upload-media
    // NOTE: route passes {builder}, method binds it positionally as int $bid — verifies
    // ownership now (previously this endpoint didn't check the builder at all).
    public function uploadMedia(Request $request, int $bid): JsonResponse
    {
        $this->builder($bid);

        $request->validate([
            'file'    => ['required', 'file', 'max:102400'], // 100MB hard cap
            'old_url' => ['nullable', 'string'],  // set when REPLACING an existing block's file (edit mode)
            'node_id' => ['nullable', 'integer'], // set when editing an existing node — links immediately
        ]);

        $company = $request->user()->company;
        $file    = $request->file('file');
        $size    = $file->getSize();

        // Replacing an existing block's file → delete the old one first and credit storage back
        if ($request->filled('old_url')) {
            $old = MediaAsset::where('company_id', $company->id)
                ->where('url', $request->old_url)
                ->first();

            if ($old) {
                Storage::disk($old->disk)->delete($old->path);
                $company->decrement('storage_used_bytes', $old->size);
                $old->delete();
            }
        }

        $limit = $company->storage_limit_bytes;
        if ($company->fresh()->storage_used_bytes + $size > $limit) {
            return response()->json([
                'message' => 'Storage limit reached. Free up space or upgrade your plan to upload more media.',
            ], 422);
        }

        $path = $file->store("flow-media/{$company->id}", 'public');
        $url  = Storage::disk('public')->url($path);

        $flowNodeId = null;
        if ($request->filled('node_id')) {
            $ok = FlowNode::where('id', $request->node_id)->where('flow_builder_id', $bid)->exists();
            if ($ok) $flowNodeId = (int) $request->node_id;
        }

        $asset = MediaAsset::create([
            'company_id'    => $company->id,
            'flow_node_id'  => $flowNodeId, // null for a brand-new node — linkNodeMediaAssets() backfills it on save
            'disk'          => 'public',
            'path'          => $path,
            'url'           => $url,
            'mime_type'     => $file->getClientMimeType(),
            'original_name' => $file->getClientOriginalName(),
            'size'          => $size,
        ]);

        $company->increment('storage_used_bytes', $size);

        return response()->json([
            'url'       => $url,
            'size'      => $size,
            'mime_type' => $asset->mime_type,
            'asset_id'  => $asset->id,
        ]);
    }
}

// class FlowNodeController extends Controller
// {
//     // Verify builder belongs to company
//     private function builder(int $bid): FlowBuilder
//     {
//         return FlowBuilder::where('id', $bid)
//             ->where('company_id', auth()->user()->company_id)
//             ->firstOrFail();
//     }

//     // GET /flow-builders/{bid}/nodes — flat list
//     public function index(int $bid): JsonResponse
//     {
//         $this->builder($bid);

//         $nodes = FlowNode::where('flow_builder_id', $bid)
//             ->where('company_id', auth()->user()->company_id)
//             ->orderByRaw('ISNULL(parent_id) DESC')
//             ->orderBy('sort_order')
//             ->get();

//         return response()->json(['nodes' => $nodes]);
//     }

//     private function sharedRules(): array
//     {
//         return [
//             'multi_messages'             => ['nullable', 'array', 'max:10'],
//             'multi_messages.*.type'      => ['required_with:multi_messages', Rule::in(['text', 'image', 'video', 'document', 'audio', 'location'])],
//             'multi_messages.*.content'   => ['nullable', 'string', 'max:4096'],
//             'multi_messages.*.url'       => ['nullable', 'url'],
//             'multi_messages.*.caption'   => ['nullable', 'string', 'max:255'],
//             'multi_messages.*.filename'  => ['nullable', 'string', 'max:150'],
//             'multi_messages.*.size'      => ['nullable', 'integer'],
//             'multi_messages.*.mime_type' => ['nullable', 'string', 'max:150'],
//             'multi_messages.*.lat'       => ['nullable', 'numeric'],
//             'multi_messages.*.lng'       => ['nullable', 'numeric'],
//             'multi_messages.*.name'      => ['nullable', 'string'],
//             'multi_messages.*.address'   => ['nullable', 'string'],

//             'lead_category'    => ['nullable', 'string', 'max:100'],
//             'sort_order'       => ['nullable', 'integer'],
//             'is_active'        => ['nullable', 'boolean'],

//             'media_type'       => ['nullable', Rule::in(['image', 'video', 'document', 'audio', 'location'])],
//             'media_url'        => ['nullable', 'url'],
//             'media_caption'    => ['nullable', 'string', 'max:255'],
//             'media_filename'   => ['nullable', 'string', 'max:150'],
//             'location_lat'     => ['nullable', 'numeric'],
//             'location_lng'     => ['nullable', 'numeric'],
//             'location_name'    => ['nullable', 'string', 'max:150'],
//             'location_address' => ['nullable', 'string', 'max:255'],

//             // Dynamic node (options loaded from an external API at runtime)
//             'is_dynamic'                 => ['nullable', 'boolean'],
//             'dynamic_api_url'            => ['nullable', 'required_if:is_dynamic,true', 'url', 'max:500'],
//             'dynamic_api_method'         => ['nullable', Rule::in(['GET', 'POST'])],
//             'dynamic_api_headers'        => ['nullable', 'string'],
//             'dynamic_label_field'        => ['nullable', 'string', 'max:100'],
//             'dynamic_value_field'        => ['nullable', 'string', 'max:100'],
//             'dynamic_description_field'  => ['nullable', 'string', 'max:100'],
//             'dynamic_image_field'        => ['nullable', 'string', 'max:100'],
//             'dynamic_subtitle_field'     => ['nullable', 'string', 'max:100'],
//         ];
//     }

//     // Blocks whose type needs media/location must actually have it — url:null slips
//     // past `nullable|url` silently otherwise, which is how empty uploads got saved.
//     private function assertMultiMessagesComplete(array $messages): ?JsonResponse
//     {
//         foreach ($messages as $i => $m) {
//             if (in_array($m['type'], ['image', 'video', 'document', 'audio']) && empty($m['url'])) {
//                 return response()->json(['message' => "Message block #" . ($i + 1) . " ({$m['type']}) is missing a media URL — upload the file first."], 422);
//             }
//             if ($m['type'] === 'location' && (empty($m['lat']) || empty($m['lng']))) {
//                 return response()->json(['message' => "Message block #" . ($i + 1) . " (location) is missing lat/lng."], 422);
//             }
//         }
//         return null;
//     }

//     // Backfill flow_node_id on media_assets uploaded for this node's blocks — covers
//     // the "create" path where files were uploaded before the node existed.
//     private function linkNodeMediaAssets(FlowNode $node): void
//     {
//         $urls = collect($node->multi_messages ?? [])->pluck('url')->filter()->values();
//         if ($node->media_url) $urls->push($node->media_url);
//         if ($urls->isEmpty()) return;

//         MediaAsset::where('company_id', $node->company_id)
//             ->whereIn('url', $urls->all())
//             ->update(['flow_node_id' => $node->id]);
//     }

//     // Delete every media_assets row (+ file + credit storage back) linked to this node
//     private function deleteNodeMediaAssets(FlowNode $node): void
//     {
//         $assets = MediaAsset::where('flow_node_id', $node->id)->get();
//         if ($assets->isEmpty()) return;

//         foreach ($assets as $asset) {
//             Storage::disk($asset->disk)->delete($asset->path);
//         }

//         auth()->user()->company()->decrement('storage_used_bytes', $assets->sum('size'));
//         MediaAsset::whereIn('id', $assets->pluck('id'))->delete();
//     }

//     // POST /flow-builders/{bid}/nodes — create node
//     public function store(Request $request, int $bid): JsonResponse
//     {
//         $this->builder($bid);
//         $cid = auth()->user()->company_id;

//         $d = $request->validate([
//             'parent_id'   => ['nullable', 'integer'],
//             'title'       => ['required', 'string', 'max:100'],
//             'message'     => ['required', 'string', 'max:4096'],
//             'type'        => ['required', Rule::in(['text', 'button', 'list', 'image', 'video', 'document', 'audio', 'location'])],
//             'reply_id'    => ['required', 'string', 'max:200'],
//             ...$this->sharedRules(),
//         ]);

//         if ($err = $this->assertMultiMessagesComplete($d['multi_messages'] ?? [])) return $err;

//         if (FlowNode::where('flow_builder_id', $bid)->where('reply_id', $d['reply_id'])->exists()) {
//             return response()->json(['message' => "reply_id '{$d['reply_id']}' already used in this builder."], 422);
//         }

//         if (!empty($d['parent_id'])) {
//             $ok = FlowNode::where('id', $d['parent_id'])->where('flow_builder_id', $bid)->exists();
//             if (!$ok) return response()->json(['message' => 'Parent node not found in this builder.'], 422);
//         }

//         $node = FlowNode::create([
//             'company_id'       => $cid,
//             'flow_builder_id'  => $bid,
//             'parent_id'        => $d['parent_id']      ?? null,
//             'title'            => $d['title'],
//             'message'          => $d['message'],
//             'multi_messages'   => $d['multi_messages']  ?? null,
//             'type'             => $d['type'],
//             'reply_id'         => $d['reply_id'],
//             'lead_category'    => $d['lead_category']   ?? null,
//             'sort_order'       => $d['sort_order']       ?? 0,
//             'is_active'        => $d['is_active']        ?? true, // was hardcoded true before, ignoring the form's toggle
//             'media_type'       => $d['media_type']       ?? null,
//             'media_url'        => $d['media_url']        ?? null,
//             'media_caption'    => $d['media_caption']    ?? null,
//             'media_filename'   => $d['media_filename']   ?? null,
//             'location_lat'     => $d['location_lat']     ?? null,
//             'location_lng'     => $d['location_lng']     ?? null,
//             'location_name'    => $d['location_name']    ?? null,
//             'location_address' => $d['location_address'] ?? null,

//             'is_dynamic'                => $d['is_dynamic']                ?? false,
//             'dynamic_api_url'           => $d['dynamic_api_url']           ?? null,
//             'dynamic_api_method'        => $d['dynamic_api_method']        ?? 'GET',
//             'dynamic_api_headers'       => $d['dynamic_api_headers']       ?? null,
//             'dynamic_label_field'       => $d['dynamic_label_field']       ?? 'name',
//             'dynamic_value_field'       => $d['dynamic_value_field']       ?? 'id',
//             'dynamic_description_field' => $d['dynamic_description_field'] ?? null,
//             'dynamic_image_field'       => $d['dynamic_image_field']       ?? null,
//             'dynamic_subtitle_field'    => $d['dynamic_subtitle_field']    ?? null,
//         ]);

//         $this->linkNodeMediaAssets($node);

//         return response()->json(['node' => $node->load('children')], 201);
//     }

//     // PUT /flow-builders/{bid}/nodes/{id}
//     public function update(Request $request, int $bid, int $id): JsonResponse
//     {
//         $this->builder($bid);

//         $node = FlowNode::where('id', $id)
//             ->where('flow_builder_id', $bid)
//             ->where('company_id', auth()->user()->company_id)
//             ->firstOrFail();

//         $d = $request->validate([
//             'title'    => ['sometimes', 'string', 'max:100'],
//             'message'  => ['sometimes', 'string', 'max:4096'],
//             'type'     => ['sometimes', Rule::in(['text', 'button', 'list', 'image', 'video', 'document', 'audio', 'location'])],
//             ...$this->sharedRules(),
//         ]);

//         if (array_key_exists('multi_messages', $d) && $err = $this->assertMultiMessagesComplete($d['multi_messages'] ?? [])) {
//             return $err;
//         }

//         $node->update($d);
//         $node = $node->fresh();

//         $this->linkNodeMediaAssets($node);

//         return response()->json(['node' => $node->load('children')]);
//     }

//     // DELETE /flow-builders/{bid}/nodes/{id}
//     public function destroy(int $bid, int $id): JsonResponse
//     {
//         $this->builder($bid);

//         $node = FlowNode::where('id', $id)
//             ->where('flow_builder_id', $bid)
//             ->where('company_id', auth()->user()->company_id)
//             ->firstOrFail();

//         $childCount = FlowNode::where('parent_id', $id)->count();
//         if ($childCount > 0) {
//             return response()->json([
//                 'message' => "Delete {$childCount} child node(s) first before deleting this node.",
//             ], 422);
//         }

//         $this->deleteNodeMediaAssets($node);

//         $node->delete();
//         return response()->json(['message' => 'Node deleted.']);
//     }

//     // POST /flow-builders/{bid}/nodes/{id}/toggle — activate / deactivate
//     public function toggle(int $bid, int $id): JsonResponse
//     {
//         $this->builder($bid);

//         $node = FlowNode::where('id', $id)
//             ->where('flow_builder_id', $bid)
//             ->where('company_id', auth()->user()->company_id)
//             ->firstOrFail();

//         $node->update(['is_active' => !$node->is_active]);

//         return response()->json([
//             'message' => 'Node ' . ($node->is_active ? 'activated' : 'deactivated') . '.',
//             'node'    => $node->fresh(),
//         ]);
//     }

//     // POST /flow-builders/{bid}/nodes/reorder — drag-drop sort
//     public function reorder(Request $request, int $bid): JsonResponse
//     {
//         $this->builder($bid);
//         $cid = auth()->user()->company_id;

//         $d = $request->validate([
//             'order'              => ['required', 'array'],
//             'order.*.id'         => ['required', 'integer'],
//             'order.*.sort_order' => ['required', 'integer'],
//         ]);

//         DB::transaction(function () use ($d, $bid, $cid) {
//             foreach ($d['order'] as $item) {
//                 FlowNode::where('id', $item['id'])
//                     ->where('flow_builder_id', $bid)
//                     ->where('company_id', $cid)
//                     ->update(['sort_order' => $item['sort_order']]);
//             }
//         });

//         return response()->json(['message' => 'Nodes reordered.']);
//     }

//     // GET /flow-builders/{builder}/nodes/check-reply-id
//     public function checkReplyId(FlowBuilder $builder, Request $request): JsonResponse
//     {
//         $request->validate(['reply_id' => 'required|string|max:200']);

//         $exists = FlowNode::where('company_id', $request->user()->company_id)
//             ->where('flow_builder_id', $builder->id)
//             ->where('reply_id', $request->reply_id)
//             ->when($request->exclude_id, fn($q, $id) => $q->where('id', '!=', $id))
//             ->exists();

//         return response()->json(['exists' => $exists]);
//     }

//     // POST /flow-builders/{bid}/nodes/upload-media
//     // NOTE: route passes {builder}, method binds it positionally as int $bid — verifies
//     // ownership now (previously this endpoint didn't check the builder at all).
//     public function uploadMedia(Request $request, int $bid): JsonResponse
//     {
//         $this->builder($bid);

//         $request->validate([
//             'file'    => ['required', 'file', 'max:102400'], // 100MB hard cap
//             'old_url' => ['nullable', 'string'],  // set when REPLACING an existing block's file (edit mode)
//             'node_id' => ['nullable', 'integer'], // set when editing an existing node — links immediately
//         ]);

//         $company = $request->user()->company;
//         $file    = $request->file('file');
//         $size    = $file->getSize();

//         // Replacing an existing block's file → delete the old one first and credit storage back
//         if ($request->filled('old_url')) {
//             $old = MediaAsset::where('company_id', $company->id)
//                 ->where('url', $request->old_url)
//                 ->first();

//             if ($old) {
//                 Storage::disk($old->disk)->delete($old->path);
//                 $company->decrement('storage_used_bytes', $old->size);
//                 $old->delete();
//             }
//         }

//         $limit = $company->storage_limit_bytes;
//         if ($company->fresh()->storage_used_bytes + $size > $limit) {
//             return response()->json([
//                 'message' => 'Storage limit reached. Free up space or upgrade your plan to upload more media.',
//             ], 422);
//         }

//         $path = $file->store("flow-media/{$company->id}", 'public');
//         $url  = Storage::disk('public')->url($path);

//         $flowNodeId = null;
//         if ($request->filled('node_id')) {
//             $ok = FlowNode::where('id', $request->node_id)->where('flow_builder_id', $bid)->exists();
//             if ($ok) $flowNodeId = (int) $request->node_id;
//         }

//         $asset = MediaAsset::create([
//             'company_id'    => $company->id,
//             'flow_node_id'  => $flowNodeId, // null for a brand-new node — linkNodeMediaAssets() backfills it on save
//             'disk'          => 'public',
//             'path'          => $path,
//             'url'           => $url,
//             'mime_type'     => $file->getClientMimeType(),
//             'original_name' => $file->getClientOriginalName(),
//             'size'          => $size,
//         ]);

//         $company->increment('storage_used_bytes', $size);

//         return response()->json([
//             'url'       => $url,
//             'size'      => $size,
//             'mime_type' => $asset->mime_type,
//             'asset_id'  => $asset->id,
//         ]);
//     }
// }

// namespace App\Modules\Flow\Http\Controllers;

// use App\Models\FlowBuilder;
// use App\Models\FlowNode;
// use App\Modules\Flow\Http\Resources\FlowNodeResource;
// use App\Modules\Flow\Services\FlowService;
// use Illuminate\Http\JsonResponse;
// use Illuminate\Http\Request;
// use Illuminate\Routing\Controller;
// use Illuminate\Support\Facades\DB;
// use Illuminate\Support\Facades\Storage;
// use Illuminate\Validation\Rule;

// class FlowNodeController extends Controller
// {
//     // Verify builder belongs to company
//     private function builder(int $bid): FlowBuilder
//     {
//         return FlowBuilder::where('id', $bid)
//             ->where('company_id', auth()->user()->company_id)
//             ->firstOrFail();
//     }

//     // GET /flow-builders/{bid}/nodes — flat list
//     public function index(int $bid): JsonResponse
//     {
//         $this->builder($bid);

//         $nodes = FlowNode::where('flow_builder_id', $bid)
//             ->where('company_id', auth()->user()->company_id)
//             ->orderByRaw('ISNULL(parent_id) DESC')
//             ->orderBy('sort_order')
//             ->get();

//         return response()->json(['nodes' => $nodes]);
//     }

//     // POST /flow-builders/{bid}/nodes — create node
//     public function store(Request $request, int $bid): JsonResponse
//     {
//         $this->builder($bid);
//         $cid = auth()->user()->company_id;

//         $d = $request->validate([
//             'parent_id'        => ['nullable', 'integer'],
//             'title'            => ['required', 'string', 'max:100'],
//             'message'          => ['required', 'string', 'max:4096'],

//             // Multi-message: array of sequential messages sent one by one
//             'multi_messages'            => ['nullable', 'array', 'max:10'],
//             'multi_messages.*.type'     => [
//                 'required_with:multi_messages',
//                 Rule::in(['text', 'image', 'video', 'document', 'audio', 'location'])
//             ],
//             'multi_messages.*.content'  => ['nullable', 'string', 'max:4096'],
//             'multi_messages.*.url'      => ['nullable', 'url'],
//             'multi_messages.*.caption'  => ['nullable', 'string', 'max:255'],
//             'multi_messages.*.filename' => ['nullable', 'string', 'max:150'],
//             'multi_messages.*.lat'      => ['nullable', 'numeric'],
//             'multi_messages.*.lng'      => ['nullable', 'numeric'],
//             'multi_messages.*.name'     => ['nullable', 'string'],
//             'multi_messages.*.address'  => ['nullable', 'string'],

//             'type'             => ['required', Rule::in(['text', 'button', 'list', 'image', 'video', 'document', 'audio', 'location'])],
//             'reply_id'         => ['required', 'string', 'max:200'],
//             'lead_category'    => ['nullable', 'string', 'max:100'],
//             'sort_order'       => ['nullable', 'integer'],

//             // Single media (header image/video/document for button nodes)
//             'media_type'       => ['nullable', Rule::in(['image', 'video', 'document', 'audio', 'location'])],
//             'media_url'        => ['nullable', 'url'],
//             'media_caption'    => ['nullable', 'string', 'max:255'],
//             'media_filename'   => ['nullable', 'string', 'max:150'],
//             'location_lat'     => ['nullable', 'numeric'],
//             'location_lng'     => ['nullable', 'numeric'],
//             'location_name'    => ['nullable', 'string', 'max:150'],
//             'location_address' => ['nullable', 'string', 'max:255'],
//         ]);

//         // reply_id must be unique within this builder
//         if (FlowNode::where('flow_builder_id', $bid)->where('reply_id', $d['reply_id'])->exists()) {
//             return response()->json(['message' => "reply_id '{$d['reply_id']}' already used in this builder."], 422);
//         }

//         // parent_id must belong to same builder
//         if (!empty($d['parent_id'])) {
//             $ok = FlowNode::where('id', $d['parent_id'])->where('flow_builder_id', $bid)->exists();
//             if (!$ok) return response()->json(['message' => 'Parent node not found in this builder.'], 422);
//         }

//         $node = FlowNode::create([
//             'company_id'       => $cid,
//             'flow_builder_id'  => $bid,
//             'parent_id'        => $d['parent_id']      ?? null,
//             'title'            => $d['title'],
//             'message'          => $d['message'],
//             'multi_messages'   => $d['multi_messages']  ?? null,
//             'type'             => $d['type'],
//             'reply_id'         => $d['reply_id'],
//             'lead_category'    => $d['lead_category']   ?? null,
//             'sort_order'       => $d['sort_order']      ?? 0,
//             'is_active'        => true,
//             'media_type'       => $d['media_type']      ?? null,
//             'media_url'        => $d['media_url']       ?? null,
//             'media_caption'    => $d['media_caption']   ?? null,
//             'media_filename'   => $d['media_filename']  ?? null,
//             'location_lat'     => $d['location_lat']    ?? null,
//             'location_lng'     => $d['location_lng']    ?? null,
//             'location_name'    => $d['location_name']   ?? null,
//             'location_address' => $d['location_address'] ?? null,
//         ]);

//         return response()->json(['node' => $node->load('children')], 201);
//     }

//     // PUT /flow-builders/{bid}/nodes/{id}
//     public function update(Request $request, int $bid, int $id): JsonResponse
//     {
//         $this->builder($bid);

//         $node = FlowNode::where('id', $id)
//             ->where('flow_builder_id', $bid)
//             ->where('company_id', auth()->user()->company_id)
//             ->firstOrFail();

//         $d = $request->validate([
//             'title'            => ['sometimes', 'string', 'max:100'],
//             'message'          => ['sometimes', 'string', 'max:4096'],
//             'multi_messages'   => ['nullable', 'array', 'max:10'],
//             'multi_messages.*.type'     => [
//                 'required_with:multi_messages',
//                 Rule::in(['text', 'image', 'video', 'document', 'audio', 'location'])
//             ],
//             'multi_messages.*.content'  => ['nullable', 'string'],
//             'multi_messages.*.url'      => ['nullable', 'url'],
//             'multi_messages.*.caption'  => ['nullable', 'string'],
//             'multi_messages.*.filename' => ['nullable', 'string'],
//             'multi_messages.*.lat'      => ['nullable', 'numeric'],
//             'multi_messages.*.lng'      => ['nullable', 'numeric'],
//             'multi_messages.*.name'     => ['nullable', 'string'],
//             'multi_messages.*.address'  => ['nullable', 'string'],
//             'type'             => ['sometimes', Rule::in(['text', 'button', 'list', 'image', 'video', 'document', 'audio', 'location'])],
//             'lead_category'    => ['nullable', 'string', 'max:100'],
//             'sort_order'       => ['nullable', 'integer'],
//             'media_type'       => ['nullable', Rule::in(['image', 'video', 'document', 'audio', 'location'])],
//             'media_url'        => ['nullable', 'url'],
//             'media_caption'    => ['nullable', 'string', 'max:255'],
//             'media_filename'   => ['nullable', 'string', 'max:150'],
//             'location_lat'     => ['nullable', 'numeric'],
//             'location_lng'     => ['nullable', 'numeric'],
//             'location_name'    => ['nullable', 'string', 'max:150'],
//             'location_address' => ['nullable', 'string', 'max:255'],
//         ]);

//         $node->update($d);

//         return response()->json(['node' => $node->fresh()->load('children')]);
//     }

//     // DELETE /flow-builders/{bid}/nodes/{id}
//     public function destroy(int $bid, int $id): JsonResponse
//     {
//         $this->builder($bid);

//         $node = FlowNode::where('id', $id)
//             ->where('flow_builder_id', $bid)
//             ->where('company_id', auth()->user()->company_id)
//             ->firstOrFail();

//         $childCount = FlowNode::where('parent_id', $id)->count();
//         if ($childCount > 0) {
//             return response()->json([
//                 'message' => "Delete {$childCount} child node(s) first before deleting this node.",
//             ], 422);
//         }

//         $node->delete();
//         return response()->json(['message' => 'Node deleted.']);
//     }

//     // POST /flow-builders/{bid}/nodes/{id}/toggle — activate / deactivate
//     public function toggle(int $bid, int $id): JsonResponse
//     {
//         $this->builder($bid);

//         $node = FlowNode::where('id', $id)
//             ->where('flow_builder_id', $bid)
//             ->where('company_id', auth()->user()->company_id)
//             ->firstOrFail();

//         $node->update(['is_active' => !$node->is_active]);

//         return response()->json([
//             'message' => 'Node ' . ($node->is_active ? 'activated' : 'deactivated') . '.',
//             'node'    => $node->fresh(),
//         ]);
//     }

//     // POST /flow-builders/{bid}/nodes/reorder — drag-drop sort
//     public function reorder(Request $request, int $bid): JsonResponse
//     {
//         $this->builder($bid);
//         $cid = auth()->user()->company_id;

//         $d = $request->validate([
//             'order'              => ['required', 'array'],
//             'order.*.id'         => ['required', 'integer'],
//             'order.*.sort_order' => ['required', 'integer'],
//         ]);

//         DB::transaction(function () use ($d, $bid, $cid) {
//             foreach ($d['order'] as $item) {
//                 FlowNode::where('id', $item['id'])
//                     ->where('flow_builder_id', $bid)
//                     ->where('company_id', $cid)
//                     ->update(['sort_order' => $item['sort_order']]);
//             }
//         });

//         return response()->json(['message' => 'Nodes reordered.']);
//     }

//     // GET /api/flow-builders/{builder}/nodes/check-reply-id
//     public function checkReplyId(FlowBuilder $builder, Request $request): JsonResponse
//     {
//         $request->validate(['reply_id' => 'required|string|max:200']);

//         $exists = FlowNode::where('company_id', $request->user()->company_id)
//             ->where('flow_builder_id', $builder->id)
//             ->where('reply_id', $request->reply_id)
//             ->when($request->exclude_id, fn($q, $id) => $q->where('id', '!=', $id))
//             ->exists();

//         return response()->json(['exists' => $exists]);
//     }

//     // POST /api/flow-nodes/upload-media
//     public function uploadMedia(Request $request): JsonResponse
//     {
//         $request->validate(['file' => 'required|file|max:102400']); // 100MB hard cap

//         $company = $request->user()->company;
//         $file    = $request->file('file');
//         $size    = $file->getSize();

//         $limit = $company->storage_limit_bytes;
//         if ($company->storage_used_bytes + $size > $limit) {
//             return response()->json([
//                 'message' => 'Storage limit reached. Free up space or upgrade your plan to upload more media.',
//             ], 422);
//         }

//         $path = $file->store("flow-media/{$company->id}", 'public');
//         $url  = Storage::disk('public')->url($path);

//         $asset = \App\Models\MediaAsset::create([
//             'company_id'    => $company->id,
//             'disk'          => 'public',
//             'path'          => $path,
//             'url'           => $url,
//             'mime_type'     => $file->getClientMimeType(),
//             'original_name' => $file->getClientOriginalName(),
//             'size'          => $size,
//         ]);

//         $company->increment('storage_used_bytes', $size);

//         return response()->json([
//             'url' => $url,
//             'size' => $size,
//             'mime_type' => $asset->mime_type,
//             'asset_id' => $asset->id,
//         ]);
//     }
// }
