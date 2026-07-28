<?php

namespace App\Modules\Campaign\DTOs;


// ─── Campaign Filter ──────────────────────────────────────────────────────────
readonly class CampaignFilterDTO
{
    public function __construct(
        public ?string $status  = null,
        public int     $perPage = 20,
        public int     $page    = 1,
    ) {}

    public static function fromRequest(array $data): self
    {
        return new self(
            status:  $data['status']   ?? null,
            perPage: (int) ($data['per_page'] ?? 20),
            page:    (int) ($data['page']     ?? 1),
        );
    }
}
