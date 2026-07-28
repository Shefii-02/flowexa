<?php

namespace App\Modules\Wallet\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

class WalletException extends Exception
{
    public function __construct(
        string               $message,
        private readonly int $statusCode  = 400,
        private readonly ?string $errorCode = null,
    ) {
        parent::__construct($message);
    }

    public static function notFound(): self
    {
        return new self('Wallet not found for this company.', 404, 'wallet_not_found');
    }

    public static function insufficientBalance(int $current, int $needed): self
    {
        return new self(
            "Insufficient balance. Have {$current}, need {$needed} messages.",
            402,
            'insufficient_balance'
        );
    }

    public static function invalidPackage(int $messages): self
    {
        return new self("Package of {$messages} messages is not available.", 422, 'invalid_package');
    }

    public static function orderCreationFailed(): self
    {
        return new self('Payment order creation failed. Please try again.', 500, 'order_creation_failed');
    }

    public static function orderNotFound(): self
    {
        return new self('Payment order not found or already processed.', 404, 'order_not_found');
    }

    public static function signatureInvalid(): self
    {
        return new self('Payment signature verification failed. Contact support.', 422, 'signature_invalid');
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message'    => $this->getMessage(),
            'error_code' => $this->errorCode,
        ], $this->statusCode);
    }
}
