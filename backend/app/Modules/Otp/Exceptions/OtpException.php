<?php
namespace App\Modules\Otp\Exceptions;
use Exception; use Illuminate\Http\JsonResponse;
class OtpException extends Exception {
    public function __construct(string $message, private readonly int $statusCode = 400, private readonly ?string $errorCode = null)
    { parent::__construct($message); }
    public static function notFound(): self            { return new self('OTP not found or already used.', 404, 'otp_not_found'); }
    public static function expired(): self             { return new self('OTP has expired. Please request a new one.', 422, 'otp_expired'); }
    public static function invalidOtp(): self          { return new self('Invalid OTP.', 422, 'otp_invalid'); }
    public static function deviceMismatch(): self      { return new self('Device mismatch. Request OTP again on this device.', 422, 'device_mismatch'); }
    public static function waCredentialsMissing(): self{ return new self('WhatsApp credentials not configured.', 500, 'wa_credentials_missing'); }
    public static function sendFailed(string $r): self { return new self("Failed to send OTP: {$r}", 500, 'send_failed'); }
    public static function unauthorized(): self        { return new self('Invalid App ID or Private Token.', 401, 'unauthorized'); }
    public function render(): JsonResponse { return response()->json(['message' => $this->getMessage(), 'error_code' => $this->errorCode], $this->statusCode); }
}
