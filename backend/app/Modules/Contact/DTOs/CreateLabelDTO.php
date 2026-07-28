<?php

namespace App\Modules\Contact\DTOs;


// ─── Create Label ─────────────────────────────────────────────────────────────
readonly class CreateLabelDTO
{
    public function __construct(
        public string $name,
        public string $color,
    ) {}

    public static function fromRequest(array $data): self
    {
        return new self(
            name:  $data['name'],
            color: $data['color'],
        );
    }
}
