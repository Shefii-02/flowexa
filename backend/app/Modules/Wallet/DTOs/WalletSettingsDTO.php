<?php

namespace App\Modules\Wallet\DTOs;

// ─── Wallet Settings ──────────────────────────────────────────────────────────
readonly class WalletSettingsDTO
{
    public function __construct(
        public ?int  $lowBalanceAlert       = null,
        public ?bool $autoRecharge          = null,
        public ?int  $autoRechargeAmount    = null,
        public ?int  $autoRechargeThreshold = null,
    ) {}

    public static function fromRequest(array $data): self
    {
        return new self(
            lowBalanceAlert:       isset($data['low_balance_alert'])       ? (int)  $data['low_balance_alert']       : null,
            autoRecharge:          isset($data['auto_recharge'])           ? (bool) $data['auto_recharge']           : null,
            autoRechargeAmount:    isset($data['auto_recharge_amount'])    ? (int)  $data['auto_recharge_amount']    : null,
            autoRechargeThreshold: isset($data['auto_recharge_threshold']) ? (int)  $data['auto_recharge_threshold'] : null,
        );
    }
}
