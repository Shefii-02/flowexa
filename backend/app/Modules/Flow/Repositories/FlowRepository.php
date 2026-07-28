<?php

namespace App\Modules\Flow\Repositories;

use App\Models\FlowNode;
use App\Modules\Flow\DTOs\CreateFlowNodeDTO;
use App\Modules\Flow\DTOs\UpdateFlowNodeDTO;
use App\Modules\Flow\Repositories\Interfaces\FlowRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class FlowRepository implements FlowRepositoryInterface
{
    // ─── Full recursive tree ──────────────────────────────────────────────────
    public function tree(int $companyId): Collection
    {
        return FlowNode::where('company_id', $companyId)
            ->whereNull('parent_id')
            ->orderBy('sort_order')
            ->with($this->recursiveWith())
            ->get();
    }

    // ─── Flat list (for dropdowns / reordering) ───────────────────────────────
    public function flat(int $companyId): Collection
    {
        return FlowNode::where('company_id', $companyId)
            ->orderBy('sort_order')
            ->get();
    }

    // ─── Find by ID ───────────────────────────────────────────────────────────
    public function findById(int $id, int $companyId): ?FlowNode
    {
        return FlowNode::where('id', $id)
            ->where('company_id', $companyId)
            ->with($this->recursiveWith())
            ->first();
    }

    // ─── Find by reply_id (webhook matching) ──────────────────────────────────
    public function findByReplyId(string $replyId, int $companyId): ?FlowNode
    {
        return FlowNode::where('reply_id', $replyId)
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->with('children')
            ->first();
    }

    // ─── Create node ──────────────────────────────────────────────────────────
    public function create(int $companyId, CreateFlowNodeDTO $dto): FlowNode
    {
        $sortOrder = FlowNode::where('company_id', $companyId)
            ->where('parent_id', $dto->parentId)
            ->max('sort_order') ?? -1;

        $replyId = $dto->replyId ?? $this->generateReplyId($dto->title, $companyId);

        $node = FlowNode::create([
            'company_id'    => $companyId,
            'parent_id'     => $dto->parentId,
            'title'         => $dto->title,
            'message'       => $dto->message,
            'type'          => $dto->type,
            'reply_id'      => $replyId,
            'lead_category' => $dto->leadCategory,
            'sort_order'    => $sortOrder + 1,
            'is_active'     => $dto->isActive,
        ]);

        return $node->load('children');
    }

    // ─── Update node ──────────────────────────────────────────────────────────
    public function update(FlowNode $node, UpdateFlowNodeDTO $dto): FlowNode
    {
        $data = array_filter([
            'title'         => $dto->title,
            'message'       => $dto->message,
            'type'          => $dto->type,
            'parent_id'     => $dto->parentId,
            'reply_id'      => $dto->replyId,
            'lead_category' => $dto->leadCategory,
            'is_active'     => $dto->isActive,
        ], fn($v) => !is_null($v));

        $node->update($data);

        return $node->fresh($this->recursiveWith());
    }

    // ─── Toggle active ────────────────────────────────────────────────────────
    public function toggle(FlowNode $node): FlowNode
    {
        $node->update(['is_active' => !$node->is_active]);
        return $node->fresh();
    }

    // ─── Delete (cascade via DB foreign key) ──────────────────────────────────
    public function delete(FlowNode $node): void
    {
        $node->delete();
    }

    // ─── Reorder: update sort_order for each ID in sequence ──────────────────
    public function reorder(array $orderedIds, int $companyId): void
    {
        foreach ($orderedIds as $index => $id) {
            FlowNode::where('id', $id)
                ->where('company_id', $companyId)
                ->update(['sort_order' => $index]);
        }
    }

    // ─── Duplicate node + all descendants ────────────────────────────────────
    public function duplicate(FlowNode $node, int $companyId): FlowNode
    {
        $node->load($this->recursiveWith());
        return $this->deepCopy($node, $node->parent_id, $companyId);
    }

    // ─── Increment trigger counter ────────────────────────────────────────────
    public function incrementTrigger(int $nodeId): void
    {
        FlowNode::where('id', $nodeId)->increment('trigger_count');
    }

    // ─── Children of a node ───────────────────────────────────────────────────
    public function children(int $nodeId, int $companyId): Collection
    {
        return FlowNode::where('parent_id', $nodeId)
            ->where('company_id', $companyId)
            ->orderBy('sort_order')
            ->get();
    }

    // ─── Reply ID uniqueness ──────────────────────────────────────────────────
    public function replyIdExists(string $replyId, int $companyId, ?int $excludeId = null): bool
    {
        return FlowNode::where('reply_id', $replyId)
            ->where('company_id', $companyId)
            ->when($excludeId, fn($q) => $q->where('id', '!=', $excludeId))
            ->exists();
    }

    // ─── Count descendants recursively ───────────────────────────────────────
    public function countDescendants(int $nodeId): int
    {
        $children = FlowNode::where('parent_id', $nodeId)->pluck('id');
        $count    = $children->count();

        foreach ($children as $childId) {
            $count += $this->countDescendants($childId);
        }

        return $count;
    }

    // ─── Analytics: trigger counts ───────────────────────────────────────────
    public function analytics(int $companyId): Collection
    {
        return FlowNode::where('company_id', $companyId)
            ->select(['id', 'parent_id', 'title', 'type', 'trigger_count', 'is_active', 'sort_order'])
            ->orderByDesc('trigger_count')
            ->get();
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /** Eager-load children → allChildren recursively (5 levels deep is safe) */
    private function recursiveWith(): array
    {
        return ['children' => fn($q) => $q->orderBy('sort_order')
            ->with(['children' => fn($q2) => $q2->orderBy('sort_order')
                ->with(['children' => fn($q3) => $q3->orderBy('sort_order')
                    ->with(['children' => fn($q4) => $q4->orderBy('sort_order')])
                ])
            ])
        ];
    }

    /** Auto-generate a slug-based reply_id, guaranteed unique per company */
    private function generateReplyId(string $title, int $companyId): string
    {
        $base = Str::slug($title, '_');
        $id   = $base;
        $i    = 1;

        while ($this->replyIdExists($id, $companyId)) {
            $id = "{$base}_{$i}";
            $i++;
        }

        return $id;
    }

    /** Recursively copy a node and all its children */
    private function deepCopy(FlowNode $node, ?int $newParentId, int $companyId): FlowNode
    {
        $sortOrder = FlowNode::where('company_id', $companyId)
            ->where('parent_id', $newParentId)
            ->max('sort_order') ?? -1;

        $copy = FlowNode::create([
            'company_id'    => $companyId,
            'parent_id'     => $newParentId,
            'title'         => $node->title . ' (copy)',
            'message'       => $node->message,
            'type'          => $node->type,
            'reply_id'      => $this->generateReplyId($node->title . '_copy', $companyId),
            'lead_category' => $node->lead_category,
            'sort_order'    => $sortOrder + 1,
            'is_active'     => false, // copied nodes start inactive
        ]);

        foreach ($node->children ?? [] as $child) {
            $this->deepCopy($child, $copy->id, $companyId);
        }

        return $copy->load('children');
    }
}
