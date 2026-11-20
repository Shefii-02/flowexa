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
        private readonly array $extra = [],   // extra fields for the JSON error (e.g. the other contact's id)
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

    public static function phoneInvalid(): self
    {
        return new self('Enter a valid phone number with the country code (7 to 15 digits, e.g. 919876543210).', 422, 'phone_invalid');
    }

    public static function phoneTaken(string $name, int $id, bool $deleted = false): self
    {
        return new self("That number already belongs to " . ($deleted ? 'a deleted contact' : 'another contact') . " — {$name} (#{$id})." . ($deleted ? ' Use a different number.' : ' Use a different number, or merge the two contacts.'), 422, 'phone_taken', ['other_contact_id' => $id, 'other_deleted' => $deleted]);
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
        ] + $this->extra, $this->statusCode);
    }
}
