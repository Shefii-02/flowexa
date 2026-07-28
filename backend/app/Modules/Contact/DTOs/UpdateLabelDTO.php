<?php

namespace App\Modules\Contact\DTOs;


// ─── Update Label ─────────────────────────────────────────────────────────────
readonly class UpdateLabelDTO
{
    public function __construct(
        public ?string $name  = null,
        public ?string $color = null,
    ) {}

    public static function fromRequest(array $data): self
    {
        return new self(
            name:  $data['name']  ?? null,
            color: $data['color'] ?? null,
        );
    }
}
