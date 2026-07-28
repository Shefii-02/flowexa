<?php

namespace App\Modules\Contact\DTOs;


// ─── Import ───────────────────────────────────────────────────────────────────
readonly class ImportContactDTO
{
    public function __construct(
        public string $filePath,
        public array  $labelIds    = [],
        public bool   $skipDupes   = true,
    ) {}

    public static function fromRequest(array $data, string $filePath): self
    {
        return new self(
            filePath:  $filePath,
            labelIds:  $data['label_ids']    ?? [],
            skipDupes: isset($data['skip_duplicates']) ? (bool) $data['skip_duplicates'] : true,
        );
    }
}
