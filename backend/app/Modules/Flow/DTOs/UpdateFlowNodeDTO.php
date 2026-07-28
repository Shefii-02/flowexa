<?php

namespace App\Modules\Flow\DTOs;


// ─── Update Flow Node ─────────────────────────────────────────────────────────
readonly class UpdateFlowNodeDTO
{
    public function __construct(
        public ?string $title        = null,
        public ?string $message      = null,
        public ?string $type         = null,
        public ?int    $parentId     = null,
        public ?string $replyId      = null,
        public ?string $leadCategory = null,
        public ?bool   $isActive     = null,
    ) {}

    public static function fromRequest(array $data): self
    {
        return new self(
            title:        $data['title']        ?? null,
            message:      $data['message']      ?? null,
            type:         $data['type']         ?? null,
            parentId:     isset($data['parent_id']) ? (int) $data['parent_id'] : null,
            replyId:      $data['reply_id']     ?? null,
            leadCategory: $data['lead_category']?? null,
            isActive:     isset($data['is_active']) ? (bool) $data['is_active'] : null,
        );
    }
}
