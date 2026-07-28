<?php

namespace App\Modules\Campaign\DTOs;


// ─── Launch Result ────────────────────────────────────────────────────────────
readonly class LaunchResultDTO
{
    public function __construct(
        public int    $totalContacts,
        public int    $walletDebited,
        public int    $remainingBalance,
    ) {}
}
