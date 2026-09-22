<?php

namespace App\Modules\Settings\DTOs;

readonly class MessageLogFilterDTO
{
    // Frontend tab ids ('waha' / 'meta') → the values stored in message_logs.channel.
    private const CHANNEL_MAP = [
        'waha' => 'wa_chat',
        'meta' => 'wa_cloud',
    ];

    public function __construct(
        public ?string $direction = null,
        public ?string $type      = null,
        public ?string $status    = null,
        public ?string $phone     = null,
        public ?string $search    = null,
        public ?string $from      = null,
        public ?string $to        = null,
        public ?string $channel   = null,
        public int     $perPage   = 30,
        public int     $page      = 1,
    ) {}

    public static function fromRequest(array $data): self
    {
        $channel = $data['channel'] ?? null;

        return new self(
            direction: $data['direction'] ?? null,
            type:      $data['type']      ?? null,
            status:    $data['status']    ?? null,
            phone:     $data['phone']     ?? null,
            search:    $data['search']    ?? null,
            from:      $data['from']      ?? null,
            to:        $data['to']        ?? null,
            channel:   $channel ? (self::CHANNEL_MAP[$channel] ?? $channel) : null,
            perPage:   (int) ($data['per_page'] ?? 30),
            page:      (int) ($data['page']     ?? 1),
        );
    }
}
