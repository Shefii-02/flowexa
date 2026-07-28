<?php

namespace App\Modules\Flow\Repositories\Interfaces;

use App\Models\FlowNode;
use App\Modules\Flow\DTOs\CreateFlowNodeDTO;
use App\Modules\Flow\DTOs\UpdateFlowNodeDTO;
use Illuminate\Support\Collection;

interface FlowRepositoryInterface
{
    // Tree: root nodes with recursive children eager-loaded
    public function tree(int $companyId): Collection;

    // Flat: all nodes for the company ordered by sort_order
    public function flat(int $companyId): Collection;

    // Single node with its children
    public function findById(int $id, int $companyId): ?FlowNode;

    // Find by reply_id for webhook matching
    public function findByReplyId(string $replyId, int $companyId): ?FlowNode;

    // Create a node, auto-calculating sort_order
    public function create(int $companyId, CreateFlowNodeDTO $dto): FlowNode;

    // Update a node
    public function update(FlowNode $node, UpdateFlowNodeDTO $dto): FlowNode;

    // Toggle is_active
    public function toggle(FlowNode $node): FlowNode;

    // Delete node (cascades to children)
    public function delete(FlowNode $node): void;

    // Reorder: update sort_order for list of IDs
    public function reorder(array $orderedIds, int $companyId): void;

    // Deep-duplicate a node and all its children under a new parent
    public function duplicate(FlowNode $node, int $companyId): FlowNode;

    // Increment trigger_count for analytics
    public function incrementTrigger(int $nodeId): void;

    // Children of a node
    public function children(int $nodeId, int $companyId): Collection;

    // Reply ID uniqueness check
    public function replyIdExists(string $replyId, int $companyId, ?int $excludeId = null): bool;

    // Count descendants
    public function countDescendants(int $nodeId): int;

    // Analytics: trigger counts per node
    public function analytics(int $companyId): Collection;
}
