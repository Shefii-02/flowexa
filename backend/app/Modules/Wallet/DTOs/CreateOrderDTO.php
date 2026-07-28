<?php

namespace App\Modules\Wallet\DTOs;

// ─── Create Razorpay Order ────────────────────────────────────────────────────
readonly class CreateOrderDTO
{
    public function __construct(
        public int $messages, // package size (1000, 5000, etc.)
    ) {}

    public static function fromRequest(array $data): self
    {
        return new self(messages: (int) $data['package']);
    }
}
