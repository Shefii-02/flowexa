<?php

namespace App\Modules\Settings\DTOs;

// ─── Update Company Settings ──────────────────────────────────────────────────
readonly class UpdateSettingsDTO
{
    public function __construct(
        public ?string $name     = null,
        public ?string $email    = null,
        public ?string $phone    = null,
        public ?string $website  = null,
        public ?array  $settings = null,  // timezone, language, otp_template, etc.
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
