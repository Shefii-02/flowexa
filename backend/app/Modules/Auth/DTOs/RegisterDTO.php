<?php

namespace App\Modules\Auth\DTOs;

readonly class RegisterDTO
{
    public function __construct(
        public string  $companyName,
        public string  $ownerName,
        public string  $email,
        public string  $password,
        public ?string $phone = null,
    ) {}

    public static function fromRequest(array $data): self
    {
        return new self(
            companyName: $data['company_name'],
            ownerName:   $data['name'],
            email:       $data['email'],
            password:    $data['password'],
            phone:       $data['phone'] ?? null,
        );
    }
}
