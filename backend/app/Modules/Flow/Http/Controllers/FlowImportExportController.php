<?php

namespace App\Modules\Flow\Http\Controllers;

use App\Models\FlowBuilder;
use App\Models\FlowNode;
use Illuminate\Http\{JsonResponse, Request, Response};
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class FlowImportExportController extends Controller
{
    // ─── GET /flow-builders/{id}/export ──────────────────────────────────────
    public function export(int $id): Response
    {
        $cid     = auth()->user()->company_id;
        $builder = FlowBuilder::where('id', $id)
            ->where('company_id', $cid)
            ->firstOrFail();

        $nodes = FlowNode::where('flow_builder_id', $id)
            ->where('company_id', $cid)
            ->orderByRaw('ISNULL(parent_id) DESC')
            ->orderBy('sort_order')
            ->get();

        $idToReplyId = $nodes->pluck('reply_id', 'id')->toArray();

        $exportNodes = $nodes->map(fn($n) => array_filter([
            '_ref'                     => $n->reply_id,
            'parent_ref'               => $n->parent_id ? ($idToReplyId[$n->parent_id] ?? null) : null,
            'title'                    => $n->title,
            'message'                  => $n->message,
            'type'                     => $n->type,
            'reply_id'                 => $n->reply_id,
            'lead_category'            => $n->lead_category,
            'sort_order'               => $n->sort_order,
            'is_active'                => (bool) $n->is_active,
            'multi_messages'           => $n->multi_messages ?: null,
            'media_type'               => $n->media_type,
            'media_url'                => $n->media_url,
            'media_caption'            => $n->media_caption,
            'media_filename'           => $n->media_filename,
            'location_lat'             => $n->location_lat,
            'location_lng'             => $n->location_lng,
            'location_name'            => $n->location_name,
            'location_address'         => $n->location_address,
            'is_dynamic'               => $n->is_dynamic ? true : null,
            'dynamic_api_url'          => $n->dynamic_api_url,
            'dynamic_api_method'       => $n->dynamic_api_method,
            'dynamic_api_headers'      => $n->dynamic_api_headers,
            'dynamic_label_field'      => $n->dynamic_label_field,
            'dynamic_value_field'      => $n->dynamic_value_field,
            'dynamic_description_field'=> $n->dynamic_description_field,
        ], fn($v) => !is_null($v)))->values()->toArray();

        $payload = [
            '_exported_at' => now()->toIso8601String(),
            '_exported_by' => auth()->user()->name ?? 'System',
            '_node_count'  => $nodes->count(),
            '_version'     => '1.0',
            'builder' => [
                'name'             => $builder->name,
                'description'      => $builder->description,
                'trigger_type'     => $builder->trigger_type,
                'trigger_keywords' => $builder->trigger_keywords ?? [],
                'active_from'      => $builder->active_from?->toIso8601String(),
                'active_until'     => $builder->active_until?->toIso8601String(),
            ],
            'nodes' => $exportNodes,
        ];

        $filename = 'flow-'
            . str($builder->name)->slug()
            . '-' . now()->format('Ymd-His')
            . '.json';

        return response(
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            200,
            [
                'Content-Type'        => 'application/json',
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            ]
        );
    }

    // ─── POST /flow-builders/import ───────────────────────────────────────────
    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'file'     => ['required', 'file', 'mimes:json,txt', 'max:5120'],
            'activate' => ['nullable', 'boolean'],
        ]);

        $contents = file_get_contents($request->file('file')->getRealPath());
        $json     = json_decode($contents, true);

        if (json_last_error() !== JSON_ERROR_NONE || !isset($json['builder'], $json['nodes'])) {
            return response()->json([
                'message' => 'Invalid file. Must be a valid flow builder JSON export.',
            ], 422);
        }

        $v = Validator::make($json, [
            'builder.name'         => ['required', 'string', 'max:100'],
            'builder.trigger_type' => ['required', 'in:default,keyword,season'],
            'nodes'                => ['required', 'array', 'min:1'],
            'nodes.*._ref'         => ['required', 'string'],
            'nodes.*.reply_id'     => ['required', 'string', 'max:200'],
            'nodes.*.title'        => ['required', 'string'],
            'nodes.*.type'         => ['required', 'in:text,button,list,image,video,document,audio,location'],
        ]);

        if ($v->fails()) {
            return response()->json([
                'message' => 'Invalid JSON structure.',
                'errors'  => $v->errors(),
            ], 422);
        }

        $cid         = auth()->user()->company_id;
        $nodesData   = $json['nodes'];
        $builderData = $json['builder'];

        // ── Duplicate reply_id check within the import file itself ────────
        $replyIds = array_column($nodesData, 'reply_id');
        if (count($replyIds) !== count(array_unique($replyIds))) {
            $dupes = array_values(array_unique(
                array_diff_assoc($replyIds, array_unique($replyIds))
            ));
            return response()->json([
                'message' => 'Duplicate reply_id inside the import file: ' . implode(', ', $dupes),
            ], 422);
        }

        // ── Duplicate _ref check within the import file itself ────────────
        // _ref/parent_ref is an independent, file-scoped linking namespace
        // used purely to stitch parent → child relationships together during
        // import. It is NOT required to equal reply_id (customers may upload
        // fully custom files where the two differ). If two nodes share an
        // _ref, the parent/child tree becomes ambiguous, so reject it early
        // instead of silently letting the last one win.
        $refs = array_column($nodesData, '_ref');
        if (count($refs) !== count(array_unique($refs))) {
            $dupeRefs = array_values(array_unique(
                array_diff_assoc($refs, array_unique($refs))
            ));
            return response()->json([
                'message' => 'Duplicate _ref inside the import file: ' . implode(', ', $dupeRefs),
            ], 422);
        }

        // ── Resolve reply_id conflicts against existing DB nodes ──────────
        // ONE suffix applied to ALL nodes in this batch so parent_ref links stay intact
        [$nodesData, $renamedMap] = $this->resolveReplyIdConflicts($nodesData, $cid);

        $result = DB::transaction(function () use ($cid, $builderData, $nodesData, $renamedMap, $request) {

            $builder = FlowBuilder::create([
                'company_id'       => $cid,
                'created_by'       => auth()->id(),
                'name'             => $builderData['name'],
                'description'      => $builderData['description']      ?? null,
                'trigger_type'     => $builderData['trigger_type'],
                'trigger_keywords' => json_encode($builderData['trigger_keywords'] ?? []),
                'active_from'      => $builderData['active_from']      ?? null,
                'active_until'     => $builderData['active_until']     ?? null,
                'is_active'        => false,
            ]);

            $refToId = [];
            $created = 0;
            $skipped = [];

            // Roots first
            $roots    = array_filter($nodesData, fn($n) => empty($n['parent_ref']));
            $children = array_values(array_filter($nodesData, fn($n) => !empty($n['parent_ref'])));

            foreach ($roots as $nodeData) {
                $node = $this->createNode($cid, $builder->id, $nodeData, null);
                $refToId[$nodeData['_ref']] = $node->id;
                $created++;
            }

            // BFS children
            for ($pass = 0; $pass < 20 && !empty($children); $pass++) {
                $nextRound = [];
                foreach ($children as $nodeData) {
                    if (!array_key_exists($nodeData['parent_ref'], $refToId)) {
                        $nextRound[] = $nodeData;
                        continue;
                    }
                    $node = $this->createNode($cid, $builder->id, $nodeData, $refToId[$nodeData['parent_ref']]);
                    $refToId[$nodeData['_ref']] = $node->id;
                    $created++;
                }
                $children = $nextRound;
            }

            foreach ($children as $n) {
                $skipped[] = "[{$n['_ref']}] parent_ref '{$n['parent_ref']}' not resolved";
            }

            if ($request->boolean('activate')) {
                FlowBuilder::where('company_id', $cid)
                    ->where('trigger_type', $builder->trigger_type)
                    ->where('id', '!=', $builder->id)
                    ->update(['is_active' => false]);
                $builder->update(['is_active' => true]);
            }

            return [
                'builder_id'  => $builder->id,
                'name'        => $builder->name,
                'created'     => $created,
                'skipped'     => count($skipped),
                'errors'      => $skipped,
                'activated'   => $request->boolean('activate'),
                'renamed'     => $renamedMap,   // tells caller which reply_ids were suffixed
                'suffix_used' => !empty($renamedMap) ? $this->lastSuffix : null,
            ];
        });

        return response()->json([
            'message' => "Import complete. {$result['created']} nodes created" .
                         ($result['skipped']        > 0 ? ", {$result['skipped']} skipped"          : '') .
                         (!empty($result['renamed']) ? ". {$result['renamed']} reply_id(s) renamed to avoid conflicts" : '') .
                         '.',
            'result'  => $result,
        ], 201);
    }

    // ─── Resolve reply_id conflicts ───────────────────────────────────────────
    // Checks all reply_ids in the batch against existing nodes for this company.
    // If ANY conflict found → generates ONE suffix and applies it to the
    // reply_id of EVERY node in the batch.
    //
    // IMPORTANT: _ref / parent_ref are a SEPARATE, file-scoped linking
    // namespace used only to stitch parent → child relationships together
    // while importing. They are independent of reply_id (a customer's file
    // is free to use arbitrary _ref labels like "root", "lang_ml", etc. that
    // don't match reply_id at all) and must be left completely untouched
    // here. Only reply_id is renamed.
    //
    // Suffix format: _{companyId}{unix_timestamp}  e.g. _6_1723000000
    // Max reply_id length is 200 — suffix is trimmed from the left if needed.

    private string $lastSuffix = '';

    private function resolveReplyIdConflicts(array $nodesData, int $companyId): array
    {
        $incomingReplyIds = array_column($nodesData, 'reply_id');

        // Fetch all existing reply_ids for this company in one query
        $existingReplyIds = FlowNode::where('company_id', $companyId)
            ->whereIn('reply_id', $incomingReplyIds)
            ->pluck('reply_id')
            ->toArray();

        // No conflict at all — return unchanged
        if (empty($existingReplyIds)) {
            return [$nodesData, 0];
        }

        // Generate ONE suffix for the entire batch
        $suffix           = '_' . $companyId . '_' . time();
        $this->lastSuffix = $suffix;
        $maxLen           = 200;

        $renamedCount = 0;

        // Apply suffix to EVERY node's reply_id only.
        // _ref and parent_ref are left exactly as-is so the parent/child
        // tree (which is keyed off _ref, not reply_id) stays intact.
        $updatedNodes = array_map(function (array $node) use ($suffix, $maxLen, &$renamedCount, $existingReplyIds) {
            $oldReplyId       = $node['reply_id'];
            $node['reply_id'] = $this->applySuffix($oldReplyId, $suffix, $maxLen);

            if (in_array($oldReplyId, $existingReplyIds, true)) {
                $renamedCount++;
            }

            return $node;
        }, $nodesData);

        return [$updatedNodes, $renamedCount];
    }

    // ─── Apply suffix, trimming from left if reply_id would exceed max length ─
    private function applySuffix(string $replyId, string $suffix, int $maxLen): string
    {
        $combined = $replyId . $suffix;
        if (mb_strlen($combined) <= $maxLen) {
            return $combined;
        }
        // Trim from left so suffix is always preserved at the end
        $trimmed = mb_substr($replyId, 0, $maxLen - mb_strlen($suffix));
        return $trimmed . $suffix;
    }

    // ─── Node factory ─────────────────────────────────────────────────────────
    private function createNode(int $companyId, int $builderId, array $d, ?int $parentId): FlowNode
    {
        return FlowNode::create([
            'company_id'               => $companyId,
            'flow_builder_id'          => $builderId,
            'parent_id'                => $parentId,
            'title'                    => mb_substr($d['title'], 0, 24),
            'message'                  => $d['message']                  ?? '',
            'type'                     => $d['type']                     ?? 'text',
            'reply_id'                 => $d['reply_id'],
            'lead_category'            => $d['lead_category']            ?? null,
            'sort_order'               => $d['sort_order']               ?? 0,
            'is_active'                => $d['is_active']                ?? true,
            'multi_messages'           => $d['multi_messages']           ?? null,
            'media_type'               => $d['media_type']               ?? null,
            'media_url'                => $d['media_url']                ?? null,
            'media_caption'            => $d['media_caption']            ?? null,
            'media_filename'           => $d['media_filename']           ?? null,
            'location_lat'             => $d['location_lat']             ?? null,
            'location_lng'             => $d['location_lng']             ?? null,
            'location_name'            => $d['location_name']            ?? null,
            'location_address'         => $d['location_address']         ?? null,
            'is_dynamic'               => $d['is_dynamic']               ?? false,
            'dynamic_api_url'          => $d['dynamic_api_url']          ?? null,
            'dynamic_api_method'       => $d['dynamic_api_method']       ?? null,
            'dynamic_api_headers'      => $d['dynamic_api_headers']      ?? null,
            'dynamic_label_field'      => $d['dynamic_label_field']      ?? null,
            'dynamic_value_field'      => $d['dynamic_value_field']      ?? null,
            'dynamic_description_field'=> $d['dynamic_description_field']?? null,
        ]);
    }
}


// ════════════════════════════════════════════════════════════════════════════
// ROUTES — routes/api.php
// NOTE: import must be BEFORE {id} route
// ════════════════════════════════════════════════════════════════════════════

// Route::post('flow-builders/import',      [FlowImportExportController::class, 'import']);
// Route::get ('flow-builders/{id}/export', [FlowImportExportController::class, 'export']);
