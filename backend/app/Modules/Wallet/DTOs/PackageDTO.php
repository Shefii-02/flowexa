<?php

namespace App\Modules\Wallet\DTOs;

// ─── Package (available recharge options) ─────────────────────────────────────
readonly class PackageDTO
{
    public function __construct(
        public int    $messages,
        public int    $priceInr,
        public string $label,
        public bool   $popular = false,
    ) {}
}
