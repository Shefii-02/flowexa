<?php

namespace App\Modules\Flow\Services;

use App\Models\FlowNode;
use App\Modules\Flow\DTOs\CreateFlowNodeDTO;
use App\Modules\Flow\DTOs\ReorderFlowDTO;
use App\Modules\Flow\DTOs\UpdateFlowNodeDTO;
use App\Modules\Flow\Exceptions\FlowException;
use App\Modules\Flow\Repositories\Interfaces\FlowRepositoryInterface;
use Illuminate\Support\Collection;

class FlowService
{
    private const MAX_TITLE_LEN   = 24;  // WhatsApp button limit
    private const MAX_MESSAGE_LEN = 1024;
    private const MAX_DEPTH       = 5;   // guard against deeply nested trees
    private const BUTTON_MAX_CHILDREN = 3;
    private const LIST_MAX_CHILDREN   = 10;

    public function __construct(
        private readonly FlowRepositoryInterface $flowRepository,
    ) {}

    // ─── Full tree ────────────────────────────────────────────────────────────
    public function tree(int $companyId): Collection
    {
        return $this->flowRepository->tree($companyId);
    }

    // ─── Flat list ────────────────────────────────────────────────────────────
    public function flat(int $companyId): Collection
    {
        return $this->flowRepository->flat($companyId);
    }

    // ─── Show node ────────────────────────────────────────────────────────────
    public function show(int $id, int $companyId): FlowNode
    {
        $node = $this->flowRepository->findById($id, $companyId);
        if (!$node) throw FlowException::notFound();
        return $node;
    }

    // ─── Create node ──────────────────────────────────────────────────────────
    public function create(int $companyId, CreateFlowNodeDTO $dto): FlowNode
    {
        // Validate parent belongs to same company
        if ($dto->parentId) {
            $parent = $this->flowRepository->findById($dto->parentId, $companyId);
            if (!$parent) throw FlowException::parentNotFound();

            // Depth guard
            $depth = $this->calculateDepth($dto->parentId, $companyId);
            if ($depth >= self::MAX_DEPTH) {
                throw FlowException::maxDepthExceeded(self::MAX_DEPTH);
            }

            // Child count guard per type
            $childCount = $this->flowRepository->children($dto->parentId, $companyId)->count();
            $this->validateChildCount($parent->type, $childCount);
        }

        // Reply ID uniqueness
        if ($dto->replyId && $this->flowRepository->replyIdExists($dto->replyId, $companyId)) {
            throw FlowException::replyIdDuplicate($dto->replyId);
        }

        return $this->flowRepository->create($companyId, $dto);
    }

    // ─── Update node ──────────────────────────────────────────────────────────
    public function update(int $id, int $companyId, UpdateFlowNodeDTO $dto): FlowNode
    {
        $node = $this->flowRepository->findById($id, $companyId);
        if (!$node) throw FlowException::notFound();

        // Guard: cannot set parent to itself or its own descendant
        if ($dto->parentId) {
            if ($dto->parentId === $id) {
                throw FlowException::circularReference();
            }

            $parent = $this->flowRepository->findById($dto->parentId, $companyId);
            if (!$parent) throw FlowException::parentNotFound();

            if ($this->isDescendant($dto->parentId, $id, $companyId)) {
                throw FlowException::circularReference();
            }
        }

        // Reply ID uniqueness (excluding self)
        if ($dto->replyId && $this->flowRepository->replyIdExists($dto->replyId, $companyId, $id)) {
            throw FlowException::replyIdDuplicate($dto->replyId);
        }

        return $this->flowRepository->update($node, $dto);
    }

    // ─── Toggle active ────────────────────────────────────────────────────────
    public function toggle(int $id, int $companyId): FlowNode
    {
        $node = $this->flowRepository->findById($id, $companyId);
        if (!$node) throw FlowException::notFound();
        return $this->flowRepository->toggle($node);
    }

    // ─── Delete ───────────────────────────────────────────────────────────────
    public function delete(int $id, int $companyId): int
    {
        $node = $this->flowRepository->findById($id, $companyId);
        if (!$node) throw FlowException::notFound();

        $descendantCount = $this->flowRepository->countDescendants($id);
        $this->flowRepository->delete($node);

        return $descendantCount; // return count so controller can report it
    }

    // ─── Reorder ──────────────────────────────────────────────────────────────
    public function reorder(int $companyId, ReorderFlowDTO $dto): void
    {
        // Validate all IDs belong to this company
        $ownedIds = $this->flowRepository->flat($companyId)->pluck('id')->toArray();

        foreach ($dto->items as $id) {
            if (!in_array($id, $ownedIds)) {
                throw FlowException::unauthorized();
            }
        }

        $this->flowRepository->reorder($dto->items, $companyId);
    }

    // ─── Duplicate ────────────────────────────────────────────────────────────
    public function duplicate(int $id, int $companyId): FlowNode
    {
        $node = $this->flowRepository->findById($id, $companyId);
        if (!$node) throw FlowException::notFound();

        return $this->flowRepository->duplicate($node, $companyId);
    }

    // ─── Analytics ────────────────────────────────────────────────────────────
    public function analytics(int $companyId): Collection
    {
        return $this->flowRepository->analytics($companyId);
    }

    // ─── Used by webhook: find node by reply_id ───────────────────────────────
    public function findByReplyId(string $replyId, int $companyId): ?FlowNode
    {
        return $this->flowRepository->findByReplyId($replyId, $companyId);
    }

    // ─── Used by webhook: increment trigger count ─────────────────────────────
    public function incrementTrigger(int $nodeId): void
    {
        $this->flowRepository->incrementTrigger($nodeId);
    }

    // ─── Private helpers ─────────────────────────────────────────────────────

    private function calculateDepth(int $nodeId, int $companyId, int $depth = 0): int
    {
        $node = $this->flowRepository->findById($nodeId, $companyId);
        if (!$node || !$node->parent_id) return $depth;
        return $this->calculateDepth($node->parent_id, $companyId, $depth + 1);
    }

    private function isDescendant(int $potentialDescendant, int $ancestorId, int $companyId): bool
    {
        $children = $this->flowRepository->children($ancestorId, $companyId);
        foreach ($children as $child) {
            if ($child->id === $potentialDescendant) return true;
            if ($this->isDescendant($potentialDescendant, $child->id, $companyId)) return true;
        }
        return false;
    }

    private function validateChildCount(string $parentType, int $currentCount): void
    {
        if ($parentType === 'button' && $currentCount >= self::BUTTON_MAX_CHILDREN) {
            throw FlowException::buttonChildLimit(self::BUTTON_MAX_CHILDREN);
        }

        if ($parentType === 'list' && $currentCount >= self::LIST_MAX_CHILDREN) {
            throw FlowException::listChildLimit(self::LIST_MAX_CHILDREN);
        }
    }
}
