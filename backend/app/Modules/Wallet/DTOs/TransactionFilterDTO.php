<?php

namespace App\Modules\Wallet\DTOs;



// ─── Transaction Filter ───────────────────────────────────────────────────────
readonly class TransactionFilterDTO
{
    public function __construct(
        public ?string $type    = null, // credit | debit
        public int     $perPage = 20,
        public int     $page    = 1,
        public array   $filters = [],   // from, to, source, q
    ) {}

    public static function fromRequest(array $data): self
    {
        return new self(
            type:    $data['type']     ?? null,
            perPage: (int) ($data['per_page'] ?? 20),
            page:    (int) ($data['page']     ?? 1),
            filters: array_filter(array_intersect_key($data, array_flip(['from', 'to', 'source', 'q'])), fn ($v) => $v !== null && $v !== ''),
        );
    }
}
