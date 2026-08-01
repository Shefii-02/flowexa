<?php

namespace App\Modules\Contact\DTOs;


// ─── Update Contact ───────────────────────────────────────────────────────────
readonly class UpdateContactDTO
{
    public function __construct(
        public ?string $name         = null,
        public ?string $email        = null,
        public ?array  $customFields = null,
        public ?bool   $optedIn      = true,
    ) {}

    public static function fromRequest(array $data): self
    {
        return new self(
            name: $data['name']          ?? null,
            email: $data['email']         ?? null,
            customFields: $data['custom_fields'] ?? null,
            optedIn: isset($data['opted_in']) ? (bool) $data['opted_in'] : true,
        );
    }
}
