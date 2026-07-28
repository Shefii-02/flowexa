<?php

namespace App\Modules\Staff\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

class StaffException extends Exception
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
        return new self('Staff member not found.', 404, 'staff_not_found');
    }

    public static function roleNotFound(): self
    {
        return new self('The selected role does not exist.', 422, 'role_not_found');
    }

    public static function protectedRole(string $roleName): self
    {
        return new self(
            "The role '{$roleName}' cannot be assigned by company administrators.",
            403,
            'protected_role'
        );
    }

    public static function cannotDeactivateSelf(): self
    {
        return new self('You cannot deactivate your own account.', 422, 'cannot_deactivate_self');
    }

    public static function cannotDeleteSelf(): self
    {
        return new self('You cannot delete your own account.', 422, 'cannot_delete_self');
    }

    public static function atCapacity(string $name, int $max): self
    {
        return new self(
            "{$name} has reached maximum lead capacity ({$max} leads).",
            422,
            'staff_at_capacity'
        );
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message'    => $this->getMessage(),
            'error_code' => $this->errorCode,
        ], $this->statusCode);
    }
}
