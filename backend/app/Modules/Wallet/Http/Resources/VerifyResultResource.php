<?php

namespace App\Modules\Wallet\Http\Resources;

use App\Modules\Wallet\DTOs\OrderResultDTO;
use App\Modules\Wallet\DTOs\PackageDTO;
use App\Modules\Wallet\DTOs\VerifyResultDTO;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;



// ─── Verify Result Resource ───────────────────────────────────────────────────
class VerifyResultResource
{
    public static function toArray(VerifyResultDTO $dto): array
    {
        return [
            'success'        => $dto->success,
            'credited'       => $dto->credited,
            'new_balance'    => $dto->newBalance,
            'transaction_id' => $dto->transactionId,
        ];
    }
}
