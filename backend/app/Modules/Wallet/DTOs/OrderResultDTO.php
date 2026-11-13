<?php

namespace App\Modules\Wallet\DTOs;

// ─── Razorpay Order Result ────────────────────────────────────────────────────
readonly class OrderResultDTO
{
    public function __construct(
        public string $orderId,
        public int    $amountPaise,
        public string $currency,
        public string $razorpayKey,
        public int    $messages,
        public int    $priceInr,
        public float  $discount = 0,
        public ?string $couponCode = null,
        public bool   $free = false,
        public ?int   $newBalance = null,
    ) {}
}
