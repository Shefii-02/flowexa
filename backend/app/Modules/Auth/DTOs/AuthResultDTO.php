<?php

namespace App\Modules\Auth\DTOs;

use App\Models\User;

readonly class AuthResultDTO
{
    public function __construct(
        public TokenDTO $token,
        public User     $user,
    ) {}
}
