<?php

namespace App\Modules\Flow\Services;

use App\Models\FlowBuilder;
use App\Models\FlowNode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FlowService
{
    // ════════════════════════════════════════════════════════════════════
    // FLOW BUILDERS
    // ════════════════════════════════════════════════════════════════════

    public function listBuilders(int $companyId): Collection
    {
        return FlowBuilder::where('company_id', $companyId)
            ->withCount('nodes')
            ->withCount(['nodes as active_nodes_count' => fn ($q) => $q->where('is_active', true)])
            ->orderByDesc('is_active')
            ->orderByDesc('created_at')
            ->get();
    }

    public function getBuilder(int $companyId, int $builderId): FlowBuilder
    {
        return FlowBuilder::where('id', $builderId)
            ->where('company_id', $companyId)
            ->withCount('nodes')
            ->firstOrFail();
    }

    public function createBuilder(int $companyId, int $userId, array $d): FlowBuilder
    {
        if ($d['trigger_type'] === 'keyword' && empty($d['trigger_keywords'])) {
            throw ValidationException::withMessages(['trigger_keywords' => 'Keyword flow needs at least one keyword.']);
        }
        if ($d['trigger_type'] === 'season' && (empty($d['active_from']) || empty($d['active_until']))) {
            throw ValidationException::withMessages(['active_from' => 'Season flow needs active_from and active_until.']);
        }

        return FlowBuilder::create([
            'company_id'       => $companyId,
            'created_by'       => $userId,
            'name'             => $d['name'],
            'description'      => $d['description'] ?? null,
            'trigger_type'     => $d['trigger_type'],
            'trigger_keywords' => json_encode($d['trigger_keywords'] ?? []),
            'active_from'      => $d['active_from']  ?? null,
            'active_until'     => $d['active_until'] ?? null,
            'is_active'        => false, // always starts inactive — must explicitly activate
        ])->loadCount('nodes');
    }

    public function updateBuilder(FlowBuilder $builder, array $d): FlowBuilder
    {
        $builder->update([
            'name'             => $d['name']             ?? $builder->name,
            'description'      => $d['description']      ?? $builder->description,
            'trigger_type'     => $d['trigger_type']      ?? $builder->trigger_type,
            'trigger_keywords' => array_key_exists('trigger_keywords', $d)
                ? json_encode($d['trigger_keywords'])
                : $builder->trigger_keywords,
            'active_from'      => $d['active_from']      ?? $builder->active_from,
            'active_until'     => $d['active_until']     ?? $builder->active_until,
        ]);

        return $builder->fresh()->loadCount('nodes');
    }

    // Only ONE builder per trigger_type can be active at a time
    public function activateBuilder(FlowBuilder $builder): FlowBuilder
    {
        $hasRoot = FlowNode::where('company_id', $builder->company_id)
            ->where('flow_builder_id', $builder->id)
            ->whereNull('parent_id')
            ->where('is_active', true)
            ->exists();

        if (!$hasRoot) {
            throw ValidationException::withMessages([
                'builder' => 'Cannot activate — this flow builder has no active root node. Add at least one root node first.',
            ]);
        }

        DB::transaction(function () use ($builder) {
            FlowBuilder::where('company_id', $builder->company_id)
                ->where('trigger_type', $builder->trigger_type)
                ->where('id', '!=', $builder->id)
                ->update(['is_active' => false]);

            $builder->update(['is_active' => true]);
        });

        return $builder->fresh()->loadCount('nodes');
    }

    public function deactivateBuilder(FlowBuilder $builder): FlowBuilder
    {
        $builder->update(['is_active' => false]);
        return $builder->fresh();
    }

    public function deleteBuilder(FlowBuilder $builder): void
    {
        if ($builder->is_active) {
            throw ValidationException::withMessages(['builder' => 'Cannot delete an active flow builder. Deactivate it first.']);
        }

        DB::transaction(function () use ($builder) {
            FlowNode::where('flow_builder_id', $builder->id)->delete();
            $builder->delete();
        });
    }

    public function duplicateBuilder(FlowBuilder $original): FlowBuilder
    {
        $nodes = FlowNode::where('flow_builder_id', $original->id)
            ->where('company_id', $original->company_id)
            ->orderBy('id')
            ->get();

        $newBuilder = null;

        DB::transaction(function () use ($original, $nodes, &$newBuilder) {
            $newBuilder = FlowBuilder::create([
                'company_id'       => $original->company_id,
                'created_by'       => $original->created_by,
                'name'             => $original->name . ' (Copy)',
                'description'      => $original->description,
                'trigger_type'     => $original->trigger_type,
                'trigger_keywords' => $original->trigger_keywords,
                'active_from'      => null,
                'active_until'     => null,
                'is_active'        => false,
            ]);

            $idMap = [];
            foreach ($nodes as $node) {
                $new = FlowNode::create([
                    ...$node->only([
                        'title', 'message', 'multi_messages', 'type', 'lead_category',
                        'sort_order', 'is_active', 'media_type', 'media_url', 'media_id',
                        'media_caption', 'media_filename', 'location_lat', 'location_lng',
                        'location_name', 'location_address',
                    ]),
                    'company_id'      => $original->company_id,
                    'flow_builder_id' => $newBuilder->id,
                    'parent_id'       => null,
                    'reply_id'        => $node->reply_id . '_c' . $newBuilder->id,
                ]);
                $idMap[$node->id] = $new->id;
            }

            foreach ($nodes as $node) {
                if ($node->parent_id && isset($idMap[$node->parent_id])) {
                    FlowNode::where('id', $idMap[$node->id])->update(['parent_id' => $idMap[$node->parent_id]]);
                }
            }
        });

        return $newBuilder->loadCount('nodes');
    }

    // ════════════════════════════════════════════════════════════════════
    // FLOW NODES
    // ════════════════════════════════════════════════════════════════════

    public function assertBuilderOwned(int $companyId, int $builderId): FlowBuilder
    {
        return FlowBuilder::where('id', $builderId)->where('company_id', $companyId)->firstOrFail();
    }

    public function listNodesFlat(int $companyId, int $builderId): Collection
    {
        return FlowNode::where('flow_builder_id', $builderId)
            ->where('company_id', $companyId)
            ->orderByRaw('ISNULL(parent_id) DESC') // roots first
            ->orderBy('sort_order')
            ->get();
    }

  public function buildTree(int $companyId, int $builderId): \Illuminate\Support\Collection
{
    $all = FlowNode::where('company_id', $companyId)
        ->where('flow_builder_id', $builderId)
        ->orderBy('sort_order')
        ->get();

    $byParent = $all->groupBy('parent_id');

    $attach = function (FlowNode $node) use (&$attach, $byParent) {
        $children = $byParent->get($node->id, collect())->map($attach)->values();
        $node->setRelation('children', $children);
        return $node;
    };

    return $byParent->get(null, collect())->map($attach)->values();
}

    public function createNode(int $companyId, int $builderId, array $d): FlowNode
    {
        $exists = FlowNode::where('flow_builder_id', $builderId)->where('reply_id', $d['reply_id'])->exists();
        if ($exists) {
            throw ValidationException::withMessages(['reply_id' => "reply_id '{$d['reply_id']}' already exists in this builder."]);
        }

        if (!empty($d['parent_id'])) {
            $parentOk = FlowNode::where('id', $d['parent_id'])->where('flow_builder_id', $builderId)->exists();
            if (!$parentOk) {
                throw ValidationException::withMessages(['parent_id' => 'Parent node not in this builder.']);
            }
        }

        return FlowNode::create([
            'company_id'       => $companyId,
            'flow_builder_id'  => $builderId,
            'parent_id'        => $d['parent_id'] ?? null,
            'title'            => $d['title'],
            'message'          => $d['message'],
            'multi_messages'   => $d['multi_messages']   ?? null,
            'type'             => $d['type'],
            'reply_id'         => $d['reply_id'],
            'lead_category'    => $d['lead_category']    ?? null,
            'sort_order'       => $d['sort_order']       ?? 0,
            'is_active'        => true,
            'media_type'       => $d['media_type']       ?? null,
            'media_url'        => $d['media_url']        ?? null,
            'media_id'         => $d['media_id']         ?? null,
            'media_caption'    => $d['media_caption']    ?? null,
            'media_filename'   => $d['media_filename']   ?? null,
            'location_lat'     => $d['location_lat']     ?? null,
            'location_lng'     => $d['location_lng']     ?? null,
            'location_name'    => $d['location_name']    ?? null,
            'location_address' => $d['location_address'] ?? null,
        ])->load('children');
    }

    public function updateNode(FlowNode $node, array $d): FlowNode
    {
        $node->update($d);
        return $node->fresh()->load('children');
    }

    public function deleteNode(FlowNode $node): void
    {
        $childCount = FlowNode::where('parent_id', $node->id)->count();
        if ($childCount > 0) {
            throw ValidationException::withMessages(['node' => "Cannot delete — this node has {$childCount} child node(s). Delete children first."]);
        }
        $node->delete();
    }

    public function setNodeActive(FlowNode $node, bool $active): FlowNode
    {
        $node->update(['is_active' => $active]);
        return $node->fresh();
    }

    public function reorderNodes(int $companyId, int $builderId, array $order): void
    {
        DB::transaction(function () use ($order, $builderId, $companyId) {
            foreach ($order as $item) {
                FlowNode::where('id', $item['id'])
                    ->where('flow_builder_id', $builderId)
                    ->where('company_id', $companyId)
                    ->update(['sort_order' => $item['sort_order']]);
            }
        });
    }
}
