<?php

namespace App\Modules\Lead\DTOs;


// ─── Lead Note ────────────────────────────────────────────────────────────────
readonly class CreateNoteDTO
{
    public function __construct(public string $content) {}

    public static function fromRequest(array $data): self
    {
        return new self(content: $data['content']);
    }
}
