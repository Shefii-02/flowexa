<?php

namespace App\Modules\Lead\DTOs;

use Illuminate\Http\UploadedFile;

readonly class ImportLeadDTO
{
    public function __construct(
        public UploadedFile $file,
    ) {}

    public static function fromRequest(array $data): self
    {
        $file = $data['file'] ?? null;

        if (!$file instanceof UploadedFile) {
            throw new \InvalidArgumentException('A valid uploaded file is required for lead import.');
        }

        return new self(file: $file);
    }
}
