<?php

namespace App\Modules\Staff\DTOs;



// ─── Staff Filter ─────────────────────────────────────────────────────────────
readonly class StaffFilterDTO
{
    public function __construct(
        public ?string $search     = null,
        public ?string $role       = null,
        public ?string $department = null,
        public ?bool   $isActive   = null,
        public int     $perPage    = 20,
        public int     $page       = 1,
    ) {}

    public static function fromRequest(array $data): self
    {
        return new self(
            search:     $data['search']     ?? null,
            role:       $data['role']       ?? null,
            department: $data['department'] ?? null,
            isActive:   isset($data['is_active']) ? (bool) $data['is_active'] : null,
            perPage:    (int) ($data['per_page'] ?? 20),
            page:       (int) ($data['page']     ?? 1),
        );
    }
}
