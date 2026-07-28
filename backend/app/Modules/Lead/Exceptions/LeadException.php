<?php

// ─── EXCEPTIONS ───────────────────────────────────────────────────────────────
namespace App\Modules\Lead\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

class LeadException extends Exception
{
    public function __construct(string $message, private readonly int $statusCode = 400, private readonly ?string $errorCode = null)
    { parent::__construct($message); }

    public static function notFound(): self           { return new self('Lead not found.', 404, 'lead_not_found'); }
    public static function forbidden(): self          { return new self('You can only access your own leads.', 403, 'forbidden'); }
    public static function duplicateLead(): self      { return new self('An active lead already exists for this contact.', 422, 'duplicate_lead'); }
    public static function counsellorNotFound(): self { return new self('Counsellor not found in this company.', 422, 'counsellor_not_found'); }
    public static function counsellorAtCapacity(string $name, int $max): self {
        return new self("{$name} has reached max lead capacity ({$max}).", 422, 'counsellor_at_capacity');
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'error_code' => $this->errorCode], $this->statusCode);
    }
}
