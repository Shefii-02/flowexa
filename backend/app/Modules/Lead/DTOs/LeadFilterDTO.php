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
        // Created-date range (Lead.created_at) and closed-date range (Lead.enrolled_at ?? lost_at
        // — whichever terminal stage the lead reached), both inclusive, "Y-m-d".
        public ?string $createdFrom = null,
        public ?string $createdTo   = null,
        public ?string $closedFrom  = null,
        public ?string $closedTo    = null,
        public int     $perPage    = 20,
        public int     $page       = 1,
    ) {}

    public static function fromRequest(array $data): self
    {
        return new self(
            stage:       $data['stage']       ?? null,
            priority:    $data['priority']    ?? null,
            category:    $data['category']    ?? null,
            assignedTo:  isset($data['assigned_to']) ? (int) $data['assigned_to'] : null,
            source:      $data['source']      ?? null,
            search:      $data['search']      ?? null,
            createdFrom: $data['created_from'] ?? null,
            createdTo:   $data['created_to']   ?? null,
            closedFrom:  $data['closed_from']  ?? null,
            closedTo:    $data['closed_to']    ?? null,
            perPage:     (int) ($data['per_page'] ?? 20),
            page:        (int) ($data['page']     ?? 1),
        );
    }
}
