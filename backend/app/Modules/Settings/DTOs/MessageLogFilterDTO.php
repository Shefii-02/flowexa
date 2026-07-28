<?php

namespace App\Modules\Settings\DTOs;

readonly class MessageLogFilterDTO
{
    public function __construct(
        public ?string $direction = null,
        public ?string $type      = null,
        public ?string $status    = null,
        public ?string $phone     = null,
        public int     $perPage   = 30,
        public int     $page      = 1,
    ) {}

    public static function fromRequest(array $data): self
    {
        return new self(
            direction: $data['direction'] ?? null,
            type:      $data['type']      ?? null,
            status:    $data['status']    ?? null,
            phone:     $data['phone']     ?? null,
            perPage:   (int) ($data['per_page'] ?? 30),
            page:      (int) ($data['page']     ?? 1),
        );
    }
}
