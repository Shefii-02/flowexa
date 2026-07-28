<?php

namespace App\Modules\Wallet\DTOs;

// ─── Verify Payment ───────────────────────────────────────────────────────────
readonly class VerifyPaymentDTO
{
    public function __construct(
        public string $razorpayOrderId,
        public string $razorpayPaymentId,
        public string $razorpaySignature,
    ) {}

    public static function fromRequest(array $data): self
    {
        return new self(
            razorpayOrderId:   $data['razorpay_order_id'],
            razorpayPaymentId: $data['razorpay_payment_id'],
            razorpaySignature: $data['razorpay_signature'],
        );
    }
}
