<?php

// ════════════════════════════════════════════════════════════════════════════
// FILE: App\Modules\Otp\Routes\api.php
// ════════════════════════════════════════════════════════════════════════════
// Route::prefix('v1')->group(function () {
//     Route::post('otp/send',   [OtpController::class, 'send']);
//     Route::post('otp/verify', [OtpController::class, 'verify']);
// });
// Note: These routes use X-App-Id + X-Private-Token headers — NOT JWT.

// ════════════════════════════════════════════════════════════════════════════
// FILE: App\Modules\Otp\DTOs\OtpDTO.php
// ════════════════════════════════════════════════════════════════════════════
namespace App\Modules\Otp\DTOs;

readonly class SendOtpDTO
{
    public function __construct(
        public string $phone,
        public string $deviceId,
    ) {}

    public static function fromRequest(array $data): self
    {
        return new self(
            phone:    preg_replace('/\D/', '', $data['phone']),
            deviceId: $data['device_id'],
        );
    }
}

readonly class VerifyOtpDTO
{
    public function __construct(
        public string $refId,
        public string $otp,
        public string $deviceId,
    ) {}

    public static function fromRequest(array $data): self
    {
        return new self(
            refId:    $data['ref_id'],
            otp:      $data['otp'],
            deviceId: $data['device_id'],
        );
    }
}

readonly class OtpResultDTO
{
    public function __construct(
        public string $refId,
        public int    $expiresInSeconds = 900,
    ) {}
}

readonly class VerifyResultDTO
{
    public function __construct(
        public bool   $verified,
        public string $phone,
    ) {}
}
