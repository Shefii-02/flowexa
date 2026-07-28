<?php

namespace App\Modules\Lead\DTOs;

// ─── Update Lead ──────────────────────────────────────────────────────────────
readonly class UpdateLeadDTO
{
    public function __construct(
        public ?string $stage        = null,
        public ?string $priority     = null,
        public ?string $category     = null,
        public ?string $notes        = null,
        public ?string $followedUpAt = null,
    ) {}

    public static function fromRequest(array $data): self
    {
        return new self(
            stage:       $data['stage']         ?? null,
            priority:    $data['priority']      ?? null,
            category:    $data['category']      ?? null,
            notes:       $data['notes']         ?? null,
            followedUpAt:$data['followed_up_at']?? null,
        );
    }
}
