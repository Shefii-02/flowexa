<?php

namespace App\Modules\Lead\DTOs;


// ─── Bulk Assign ──────────────────────────────────────────────────────────────
readonly class BulkAssignDTO
{
    public function __construct(
        public array $leadIds,
        public array $userIds,
        public string $mode = 'round_robin', // round_robin | specific
    ) {}

    public static function fromRequest(array $data): self
    {
        return new self(
            leadIds: $data['lead_ids'],
            userIds: $data['user_ids'],
            mode:    $data['mode'] ?? 'round_robin',
        );
    }
}
