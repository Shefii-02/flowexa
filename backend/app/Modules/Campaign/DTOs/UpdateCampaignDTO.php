<?php

namespace App\Modules\Campaign\DTOs;


// ─── Update Campaign ──────────────────────────────────────────────────────────
readonly class UpdateCampaignDTO
{
    public function __construct(
        public ?string $name               = null,
        public ?string $description        = null,
        public ?array  $templateVariables  = null,
        public ?int    $throttlePerMinute  = null,
        public ?string $scheduledAt        = null,
        public ?string $startsAt           = null,
        public ?string $endsAt             = null,
    ) {}

    public static function fromRequest(array $data): self
    {
        return new self(
            name:              $data['name']               ?? null,
            description:       $data['description']        ?? null,
            templateVariables: $data['template_variables'] ?? null,
            throttlePerMinute: isset($data['throttle_per_minute']) ? (int) $data['throttle_per_minute'] : null,
            scheduledAt:       !empty($data['scheduled_at'])
                                    ? \Illuminate\Support\Carbon::parse($data['scheduled_at'])->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s') : null,
            startsAt:          $data['starts_at']          ?? null,
            endsAt:            $data['ends_at']            ?? null,
        );
    }
}
