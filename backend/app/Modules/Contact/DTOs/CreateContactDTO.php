<?php

namespace App\Modules\Contact\DTOs;

// ─── Create Contact ───────────────────────────────────────────────────────────
readonly class CreateContactDTO
{
    public function __construct(
        public string  $phone,
        public ?string $name         = null,
        public ?string $email        = null,
        public ?array  $customFields = null,
        public bool    $optedIn      = true,
        public array   $labelIds     = [],
    ) {}

    public static function fromRequest(array $data): self
    {
        return new self(
            phone:        preg_replace('/\D/', '', $data['phone']),
            name:         $data['name']          ?? null,
            email:        $data['email']         ?? null,
            customFields: $data['custom_fields'] ?? null,
            optedIn:      isset($data['opted_in']) ? (bool) $data['opted_in'] : true,
            labelIds:     $data['label_ids']     ?? [],
        );
    }
}


