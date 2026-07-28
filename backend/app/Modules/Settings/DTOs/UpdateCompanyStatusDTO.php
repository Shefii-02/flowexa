<?php

namespace App\Modules\Settings\DTOs;

readonly class UpdateCompanyStatusDTO
{
    public function __construct(
        public string $status, // active | suspended | trial
    ) {}

    public static function fromRequest(array $data): self
    {
        return new self(status: $data['status']);
    }
}
