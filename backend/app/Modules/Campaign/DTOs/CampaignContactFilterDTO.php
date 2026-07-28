<?php

namespace App\Modules\Campaign\DTOs;

// ─── Campaign Contact Filter ──────────────────────────────────────────────────
readonly class CampaignContactFilterDTO
{
    public function __construct(
        public ?string $status  = null,
        public ?string $search  = null,
        public int     $perPage = 30,
        public int     $page    = 1,
    ) {}

    public static function fromRequest(array $data): self
    {
        return new self(
            status:  $data['status']   ?? null,
            search:  $data['search']   ?? null,
            perPage: (int) ($data['per_page'] ?? 30),
            page:    (int) ($data['page']     ?? 1),
        );
    }
}
