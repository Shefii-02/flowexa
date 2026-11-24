<?php

namespace App\Modules\Lead\DTOs;

use Illuminate\Http\UploadedFile;

readonly class ImportLeadDTO
{
    public function __construct(
        public UploadedFile $file,
        /** Optional import defaults: country_code_mode, default_country_code, stage, priority, category, source, assign_to. */
        public array $options = [],
    ) {}

    public static function fromRequest(array $data): self
    {
        $file = $data['file'] ?? null;

        if (!$file instanceof UploadedFile) {
            throw new \InvalidArgumentException('A valid uploaded file is required for lead import.');
        }

        return new self(file: $file, options: array_filter(
            array_intersect_key($data, array_flip(['country_code_mode', 'default_country_code', 'stage', 'priority', 'category', 'source', 'assign_to'])),
            fn ($v) => $v !== null && $v !== '',
        ));
    }
}
