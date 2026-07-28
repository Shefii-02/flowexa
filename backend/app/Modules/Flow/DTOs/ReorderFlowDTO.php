<?php

namespace App\Modules\Flow\DTOs;

// ─── Reorder ──────────────────────────────────────────────────────────────────
readonly class ReorderFlowDTO
{
    public function __construct(
        public array $items, // [id => sort_order] or flat list of IDs
    ) {}

    public static function fromRequest(array $data): self
    {
        return new self(items: $data['items']);
    }
}
