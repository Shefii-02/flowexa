<?php
namespace App\Modules\Webhook\DTOs;

// ─── Status update from Meta ──────────────────────────────────────────────────
readonly class StatusUpdateDTO
{
    public function __construct(
        public string $waMessageId,
        public string $status,      // sent | delivered | read | failed
        public ?string $errorMessage = null,
    ) {}

    public static function fromMeta(array $status): self
    {
        return new self(
            waMessageId:  $status['id'],
            status:       $status['status'],
            errorMessage: $status['errors'][0]['message'] ?? null,
        );
    }
}
