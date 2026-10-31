<?php
namespace App\Modules\Webhook\DTOs;

// ─── Status update from Meta ──────────────────────────────────────────────────
readonly class StatusUpdateDTO
{
    public function __construct(
        public string $waMessageId,
        public string $status,      // sent | delivered | read | failed
        public ?string $errorMessage = null,
        public ?int $errorCode = null,
        public ?bool $billable = null,
        public ?string $pricingCategory = null,
        public ?string $pricingModel = null,
    ) {}

    public static function fromMeta(array $status): self
    {
        return new self(
            waMessageId:  $status['id'],
            status:       $status['status'],
            errorMessage: trim(
                ($status['errors'][0]['message'] ?? $status['errors'][0]['title'] ?? '')
                . (isset($status['errors'][0]['error_data']['details']) ? ' — ' . $status['errors'][0]['error_data']['details'] : '')
            ) ?: null,
            errorCode:    isset($status['errors'][0]['code']) ? (int) $status['errors'][0]['code'] : null,
            billable:        isset($status['pricing']['billable']) ? (bool) $status['pricing']['billable'] : null,
            pricingCategory: $status['pricing']['category'] ?? null,
            pricingModel:    $status['pricing']['pricing_model'] ?? null,
        );
    }
}
