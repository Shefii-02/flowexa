<?php

namespace App\Modules\Auth\DTOs;

use App\Models\User;
readonly class UpdateCompanyDTO
{
    public function __construct(
        public ?string $name        = null,
        public ?string $email       = null,
        public ?string $phone       = null,
        public ?string $website     = null,
        public ?array  $settings    = null,
    ) {}

    public static function fromRequest(array $data): self
    {
        return new self(
            name:     $data['name']     ?? null,
            email:    $data['email']    ?? null,
            phone:    $data['phone']    ?? null,
            website:  $data['website']  ?? null,
            settings: $data['settings'] ?? null,
        );
    }
}
