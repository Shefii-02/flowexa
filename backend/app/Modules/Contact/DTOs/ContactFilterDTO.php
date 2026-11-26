<?php

namespace App\Modules\Contact\DTOs;


// ─── Contact Filter ───────────────────────────────────────────────────────────
readonly class ContactFilterDTO
{
    public function __construct(
        public ?string $search    = null,
        public ?int    $labelId   = null,
        public ?bool   $optedIn   = null,
        public ?string $sortBy    = 'created_at',
        public ?string $sortDir   = 'desc',
        public int     $perPage   = 20,
        public int     $page      = 1,
        public bool    $unnamed   = false,   // saved without a name (just a number)
        public bool    $named     = false,
        public ?string $letter    = null,    // A–Z, or # for names that do not start with a letter
        public ?string $createdFrom = null,
        public ?string $createdTo   = null,
    ) {}

    public static function fromRequest(array $data): self
    {
        return new self(
            search:  $data['search']   ?? null,
            labelId: isset($data['label_id']) ? (int) $data['label_id'] : null,
            optedIn: isset($data['opted_in']) ? (bool) $data['opted_in'] : null,
            sortBy:  $data['sort_by']  ?? 'created_at',
            sortDir: $data['sort_dir'] ?? 'desc',
            perPage: (int) ($data['per_page'] ?? 20),
            page:    (int) ($data['page']     ?? 1),
            unnamed: (bool) ($data['unnamed'] ?? false),
            named:   (bool) ($data['named'] ?? false),
            letter:  $data['letter'] ?? null,
            createdFrom: $data['created_from'] ?? null,
            createdTo:   $data['created_to'] ?? null,
        );
    }
}
