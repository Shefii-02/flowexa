<?php

namespace App\Modules\Wallet\Http\Resources;

use App\Modules\Wallet\DTOs\OrderResultDTO;
use App\Modules\Wallet\DTOs\PackageDTO;
use App\Modules\Wallet\DTOs\VerifyResultDTO;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;


// ─── Transaction Resource ─────────────────────────────────────────────────────
class TransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'type'           => $this->type,
            'amount'         => $this->amount,
            'balance_before' => $this->balance_before,
            'balance_after'  => $this->balance_after,
            'description'    => $this->description,
            'reference_id'   => $this->reference_id,
            'reference_type' => $this->reference_type,
            'created_at'     => $this->created_at->toIso8601String(),
        ];
    }
}
