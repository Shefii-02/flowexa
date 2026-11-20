<?php

namespace App\Modules\Contact\DTOs;


// ─── Update Contact ───────────────────────────────────────────────────────────
readonly class UpdateContactDTO
{
    public function __construct(
        public ?string $name         = null,
        public ?string $email        = null,
        public ?array  $customFields = null,
        public ?bool   $optedIn      = null,    // null = not sent → opt-in status is left alone
        public ?int    $assignedTo   = null,
        public ?string $phone        = null,
    ) {}

    public static function fromRequest(array $data): self
    {
        return new self(
            name: $data['name']          ?? null,
            email: $data['email']         ?? null,
            customFields: $data['custom_fields'] ?? null,
            optedIn: array_key_exists('opted_in', $data) && $data['opted_in'] !== null ? (bool) $data['opted_in'] : null,
            assignedTo: isset($data['assigned_to']) ? (int) $data['assigned_to'] : null,
            phone: isset($data['phone']) && trim((string) $data['phone']) !== '' ? (string) $data['phone'] : null,
        );
    }
}
