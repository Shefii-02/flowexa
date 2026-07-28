<?php

namespace App\Modules\Contact\DTOs;


// ─── Import Result ────────────────────────────────────────────────────────────
class ImportResultDTO
{
    public function __construct(
        public int   $imported = 0,
        public int   $skipped  = 0,
        public int   $failed   = 0,
        public array $errors   = [],
    ) {}

    public function addError(string $msg): void { $this->errors[] = $msg; $this->failed++; }
    public function incrementImported(): void   { $this->imported++; }
    public function incrementSkipped(): void    { $this->skipped++; }
    public function total(): int               { return $this->imported + $this->skipped + $this->failed; }
}
