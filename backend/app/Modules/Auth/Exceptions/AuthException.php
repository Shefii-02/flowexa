<?php

namespace App\Modules\Auth\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

class AuthException extends Exception
{
    public function __construct(
        string               $message,
        private readonly int $statusCode = 401,
        private readonly ?string $errorCode = null,
    ) {
        parent::__construct($message);
    }

    // ─── Factory methods ──────────────────────────────────────────────────────
    public static function invalidCredentials(): self
    {
        return new self('Invalid email or password.', 401, 'invalid_credentials');
    }

    public static function accountDeactivated(): self
    {
        return new self('Your account has been deactivated. Please contact support.', 403, 'account_deactivated');
    }

    public static function companySuspended(): self
    {
        return new self('Your company account is suspended. Please contact support.', 403, 'company_suspended');
    }

    public static function tokenRefreshFailed(): self
    {
        return new self('Token refresh failed. Please login again.', 401, 'token_refresh_failed');
    }

    public static function userNotFound(): self
    {
        return new self('Authenticated user not found.', 404, 'user_not_found');
    }

    public static function unauthorized(): self
    {
        return new self('Unauthorized access.', 403, 'unauthorized');
    }

    // ─── Render as JSON ───────────────────────────────────────────────────────
    public function render(): JsonResponse
    {
        return response()->json([
            'message'    => $this->getMessage(),
            'error_code' => $this->errorCode,
        ], $this->statusCode);
    }
}
