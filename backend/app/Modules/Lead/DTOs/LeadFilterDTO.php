<?php

namespace App\Modules\Lead\DTOs;


// ─── Lead Filter ──────────────────────────────────────────────────────────────
readonly class LeadFilterDTO
{
    public function __construct(
        public ?string $stage      = null,
        public ?string $priority   = null,
        public ?string $category   = null,
        public ?int    $assignedTo = null,
        public ?string $source     = null,
        public ?string $search     = null,
        public int     $perPage    = 20,
        public int     $page       = 1,
    ) {}

    public static function fromRequest(array $data): self
    {
        return new self(
            stage:      $data['stage']       ?? null,
            priority:   $data['priority']    ?? null,
            category:   $data['category']    ?? null,
            assignedTo: isset($data['assigned_to']) ? (int) $data['assigned_to'] : null,
            source:     $data['source']      ?? null,
            search:     $data['search']      ?? null,
            perPage:    (int) ($data['per_page'] ?? 20),
            page:       (int) ($data['page']     ?? 1),
        );
    }
}
