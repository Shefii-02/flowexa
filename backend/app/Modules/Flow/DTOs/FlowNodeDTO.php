<?php

namespace App\Modules\Flow\DTOs;


// ─── Flow Node data passed between layers ─────────────────────────────────────
readonly class FlowNodeDTO
{
    public function __construct(
        public int     $id,
        public ?int    $parentId,
        public string  $title,
        public string  $message,
        public string  $type,
        public string  $replyId,
        public ?string $leadCategory,
        public int     $sortOrder,
        public bool    $isActive,
        public int     $triggerCount,
        public array   $children = [],
    ) {}
}
