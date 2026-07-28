<?php

namespace App\Modules\Settings\DTOs;

readonly class TopUpDTO
{
    public function __construct(
        public int    $amount,
        public string $description = 'Manual top-up by superadmin',
    ) {}

    public static function fromRequest(array $data): self
    {
        return new self(
            amount:      (int) $data['amount'],
            description: $data['description'] ?? 'Manual top-up by superadmin',
        );
    }
}
