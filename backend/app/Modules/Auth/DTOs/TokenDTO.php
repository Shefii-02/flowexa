<?php

namespace App\Modules\Auth\DTOs;

use App\Models\User;

readonly class TokenDTO
{
    public function __construct(
        public string $accessToken,
        public string $tokenType,
        public int    $expiresIn,
    ) {}

    public static function fromJwt(string $token): self
    {
        return new self(
            accessToken: $token,
            tokenType:   'bearer',
            expiresIn:   config('jwt.ttl') * 60,
        );
    }
}

// ─────────────────────────────────────────────────────────────────────────────


// ─────────────────────────────────────────────────────────────────────────────


// ─────────────────────────────────────────────────────────────────────────────

