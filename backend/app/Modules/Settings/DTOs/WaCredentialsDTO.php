<?php

namespace App\Modules\Settings\DTOs;

readonly class WaCredentialsDTO
{
    public function __construct(
        public string  $waPhoneId,
        public string  $waAccessToken,
        public ?string $waBusinessId = null,
    ) {}

    public static function fromRequest(array $data): self
    {
        return new self(
            waPhoneId:     $data['wa_phone_id'],
            waAccessToken: $data['wa_access_token'],
            waBusinessId:  $data['wa_business_id'] ?? null,
        );
    }
}
