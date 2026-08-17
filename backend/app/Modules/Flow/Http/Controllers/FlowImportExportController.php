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

        // Map DB id → reply_id so parent_ref can be reconstructed on import
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
            '_exported_at'  => now()->toIso8601String(),
            '_exported_by'  => auth()->user()->name ?? 'System',
            '_node_count'   => $nodes->count(),
            '_version'      => '1.0',
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

        // Parse JSON
        $contents = file_get_contents($request->file('file')->getRealPath());
        $json     = json_decode($contents, true);

        if (json_last_error() !== JSON_ERROR_NONE || !isset($json['builder'], $json['nodes'])) {
            return response()->json([
                'message' => 'Invalid file. Must be a valid flow builder JSON export.',
            ], 422);
        }

        // Validate top-level structure
        $v = Validator::make($json, [
            'builder.name'         => ['required', 'string', 'max:100'],
            'builder.trigger_type' => ['required', 'in:default,keyword,season'],
            'nodes'                => ['required', 'array', 'min:1'],
            'nodes.*._ref'         => ['required', 'string'],
            'nodes.*.reply_id'     => ['required', 'string', 'max:200'],
            'nodes.*.title'        => ['required', 'string'],
            'nodes.*.type'         => ['required', 'in:text,button,list,image,video,document,audio,location'],
            'nodes.*.message'      => ['nullable', 'string'],
        ]);

        if ($v->fails()) {
            return response()->json([
                'message' => 'Invalid JSON structure.',
                'errors'  => $v->errors(),
            ], 422);
        }

        $nodesData   = $json['nodes'];
        $builderData = $json['builder'];

        // Duplicate reply_id check within the import file itself
        $replyIds = array_column($nodesData, 'reply_id');
        if (count($replyIds) !== count(array_unique($replyIds))) {
            $dupes = array_diff_assoc($replyIds, array_unique($replyIds));
            return response()->json([
                'message' => 'Duplicate reply_id values in import file: ' . implode(', ', $dupes),
            ], 422);
        }

        $cid = auth()->user()->company_id;

        $result = DB::transaction(function () use ($cid, $builderData, $nodesData, $request) {

            // Create the builder (always inactive first)
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

            $refToId  = [];
            $created  = 0;
            $skipped  = [];

            // Roots first (no parent_ref)
            $roots    = array_filter($nodesData, fn($n) => empty($n['parent_ref']));
            $children = array_filter($nodesData, fn($n) => !empty($n['parent_ref']));

            foreach ($roots as $nodeData) {
                try {
                    $node = $this->createNode($cid, $builder->id, $nodeData, null);
                    $refToId[$nodeData['_ref']] = $node->id;
                    $created++;
                } catch (\Exception $e) {
                    $skipped[] = "[{$nodeData['_ref']}] " . $e->getMessage();
                }
            }

            // BFS for children — max 20 passes handles up to 20 levels deep
            $remaining = array_values($children);
            for ($pass = 0; $pass < 20 && !empty($remaining); $pass++) {
                $nextRound = [];
                foreach ($remaining as $nodeData) {
                    if (!isset($refToId[$nodeData['parent_ref']])) {
                        $nextRound[] = $nodeData;
                        continue;
                    }
                    try {
                        $node = $this->createNode($cid, $builder->id, $nodeData, $refToId[$nodeData['parent_ref']]);
                        $refToId[$nodeData['_ref']] = $node->id;
                        $created++;
                    } catch (\Exception $e) {
                        $skipped[] = "[{$nodeData['_ref']}] " . $e->getMessage();
                    }
                }
                $remaining = $nextRound;
            }

            // Anything still remaining = orphaned (parent_ref never resolved)
            foreach ($remaining as $n) {
                $skipped[] = "[{$n['_ref']}] parent_ref '{$n['parent_ref']}' not found";
            }

            // Activate if requested
            if ($request->boolean('activate')) {
                FlowBuilder::where('company_id', $cid)
                    ->where('trigger_type', $builder->trigger_type)
                    ->where('id', '!=', $builder->id)
                    ->update(['is_active' => false]);

                $builder->update(['is_active' => true]);
            }

            return [
                'builder_id' => $builder->id,
                'name'       => $builder->name,
                'created'    => $created,
                'skipped'    => count($skipped),
                'errors'     => $skipped,
                'activated'  => $request->boolean('activate'),
            ];
        });

        return response()->json([
            'message' => "Import complete. {$result['created']} nodes created" .
                         ($result['skipped'] > 0 ? ", {$result['skipped']} skipped." : '.'),
            'result'  => $result,
        ], 201);
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
