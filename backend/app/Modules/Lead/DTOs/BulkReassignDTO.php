<?php

namespace App\Modules\Lead\DTOs;

// ─── Bulk Reassign — switch every lead from one employee to another ───────────
readonly class BulkReassignDTO
{
    public function __construct(
        public int  $fromUserId,
        public int  $toUserId,
        public bool $includeClosed = false,
    ) {}

    public static function fromRequest(array $data): self
    {
        return new self(
            fromUserId:    (int) $data['from_user_id'],
            toUserId:      (int) $data['to_user_id'],
            includeClosed: (bool) ($data['include_closed'] ?? false),
        );
    }
}
