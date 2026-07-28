<?php

namespace App\Modules\Flow\DTOs;

// ─── Create Flow Node ─────────────────────────────────────────────────────────
readonly class CreateFlowNodeDTO
{
    public function __construct(
        public string  $title,
        public string  $message,
        public string  $type,           // list | button | text
        public ?int    $parentId      = null,
        public ?string $replyId       = null,
        public ?string $leadCategory  = null,
        public bool    $isActive      = true,
    ) {}

    public static function fromRequest(array $data): self
    {
        return new self(
            title:        $data['title'],
            message:      $data['message'],
            type:         $data['type'],
            parentId:     isset($data['parent_id']) ? (int) $data['parent_id'] : null,
            replyId:      $data['reply_id']      ?? null,
            leadCategory: $data['lead_category'] ?? null,
            isActive:     isset($data['is_active']) ? (bool) $data['is_active'] : true,
        );
    }
}
