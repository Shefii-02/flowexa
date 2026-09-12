<?php

namespace App\Modules\Lead\DTOs;

// ─── Bulk Delete — remove several selected leads at once ──────────────────────
readonly class BulkDeleteDTO
{
    /** @param int[] $leadIds */
    public function __construct(
        public array $leadIds,
    ) {}

    public static function fromRequest(array $data): self
    {
        return new self(leadIds: array_map('intval', $data['lead_ids']));
    }
}
