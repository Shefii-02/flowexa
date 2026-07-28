<?php

namespace App\Modules\Wallet\DTOs;

// ─── Verify Result ────────────────────────────────────────────────────────────
readonly class VerifyResultDTO
{
    public function __construct(
        public bool   $success,
        public int    $credited,
        public int    $newBalance,
        public string $transactionId,
    ) {}
}
