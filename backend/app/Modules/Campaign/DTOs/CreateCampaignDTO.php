<?php

namespace App\Modules\Campaign\DTOs;

// ─── Create Campaign ──────────────────────────────────────────────────────────
readonly class CreateCampaignDTO
{
    public function __construct(
        public string  $name,
        public int     $templateId,
        public int     $waPhoneNumberId,
        public string  $targetType,        // csv | labels | all
        public ?string $description        = null,
        public ?array  $templateVariables  = null,
        public ?array  $targetLabels       = null,
        public ?string $csvFilePath        = null,
        public int     $throttlePerMinute  = 60,
        public ?string $scheduledAt        = null,
    ) {}

    public static function fromRequest(array $data, ?string $csvPath = null): self
    {
        return new self(
            name:               $data['name'],
            templateId:         (int) $data['template_id'],
            waPhoneNumberId:    (int) $data['wa_phone_number_id'],
            targetType:         $data['target_type'],
            description:        $data['description']         ?? null,
            templateVariables:  $data['template_variables']  ?? null,
            targetLabels:       $data['target_labels']        ?? null,
            csvFilePath:        $csvPath,
            throttlePerMinute:  (int) ($data['throttle_per_minute'] ?? 60),
            scheduledAt:        $data['scheduled_at']         ?? null,
        );
    }
}
