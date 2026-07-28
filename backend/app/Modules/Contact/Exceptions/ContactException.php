<?php

namespace App\Modules\Contact\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

class ContactException extends Exception
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
        return new self('Contact not found.', 404, 'contact_not_found');
    }

    public static function phoneDuplicate(string $phone): self
    {
        return new self("A contact with phone {$phone} already exists.", 422, 'phone_duplicate');
    }

    public static function alreadyOptedOut(): self
    {
        return new self('Contact is already opted out.', 422, 'already_opted_out');
    }

    public static function alreadyOptedIn(): self
    {
        return new self('Contact is already opted in.', 422, 'already_opted_in');
    }

    public static function labelNotFound(): self
    {
        return new self('Label not found.', 404, 'label_not_found');
    }

    public static function labelNameDuplicate(string $name): self
    {
        return new self("A label named '{$name}' already exists.", 422, 'label_name_duplicate');
    }

    public static function invalidCsv(string $reason): self
    {
        return new self("Invalid CSV: {$reason}", 422, 'invalid_csv');
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message'    => $this->getMessage(),
            'error_code' => $this->errorCode,
        ], $this->statusCode);
    }
}
