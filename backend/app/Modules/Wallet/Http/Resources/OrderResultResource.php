<?php

namespace App\Modules\Wallet\Http\Resources;

use App\Modules\Wallet\DTOs\OrderResultDTO;
use App\Modules\Wallet\DTOs\PackageDTO;
use App\Modules\Wallet\DTOs\VerifyResultDTO;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// ─── Order Result Resource ────────────────────────────────────────────────────
class OrderResultResource
{

    public static function toArray(OrderResultDTO $dto): array
    {
         $user = auth()->user();
        return [
            'order_id'     => $dto->orderId,
            'amount'       => $dto->amountPaise,
            'currency'     => $dto->currency,
            'razorpay_key' => $dto->razorpayKey,
            'package'      => [
                'messages'  => $dto->messages,
                'price_inr' => $dto->priceInr,
            ],
            'user' => [
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
            ]
        ];
    }
}
