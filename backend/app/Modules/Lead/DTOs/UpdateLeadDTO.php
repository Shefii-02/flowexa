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
        public ?int    $listingId    = null,
        public ?float  $saleValue    = null,
    ) {}

    public static function fromRequest(array $data): self
    {
        return new self(
            stage:       $data['stage']         ?? null,
            priority:    $data['priority']      ?? null,
            category:    $data['category']      ?? null,
            notes:       $data['notes']         ?? null,
            followedUpAt:$data['followed_up_at']?? null,
            listingId:   isset($data['listing_id']) ? (int) $data['listing_id'] : null,
            saleValue:   isset($data['sale_value']) ? (float) $data['sale_value'] : null,
        );
    }
}
