<?php

namespace App\Modules\Wallet\Http\Resources;

use App\Modules\Wallet\DTOs\OrderResultDTO;
use App\Modules\Wallet\DTOs\PackageDTO;
use App\Modules\Wallet\DTOs\VerifyResultDTO;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// ─── Wallet Resource ──────────────────────────────────────────────────────────
class WalletResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'balance'               => $this->balance,
            'total_used'            => $this->total_used,
            'total_purchased'       => $this->total_purchased,
            'free_quota_used'       => $this->free_quota_used,
            'low_balance_alert'     => $this->low_balance_alert,
            'auto_recharge'         => $this->auto_recharge,
            'auto_recharge_amount'  => $this->auto_recharge_amount,
            'auto_recharge_threshold' => $this->auto_recharge_threshold,
            'free_quota_reset_at'   => $this->free_quota_reset_at?->toIso8601String(),
            'is_low'                => $this->balance <= $this->low_balance_alert,
        ];
    }
}
