<?php

namespace App\Modules\Staff\DTOs;


// ─── Reset Password ───────────────────────────────────────────────────────────
readonly class ResetPasswordDTO
{
    public function __construct(
        public string $password,
    ) {}

    public static function fromRequest(array $data): self
    {
        return new self(password: $data['password']);
    }
}
