<?php

namespace App\Modules\Lead\DTOs;



// ─── Assign Lead ──────────────────────────────────────────────────────────────
readonly class AssignLeadDTO
{
    public function __construct(public int $userId) {}

    public static function fromRequest(array $data): self
    {
        return new self(userId: (int) $data['user_id']);
    }
}
