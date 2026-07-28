<?php

namespace App\Modules\Staff\DTOs;

// ─── Create Staff ─────────────────────────────────────────────────────────────
readonly class CreateStaffDTO
{
    public function __construct(
        public string  $name,
        public string  $email,
        public string  $password,
        public int     $roleId,
        public ?string $phone      = null,
        public ?string $department = null,
        public int     $maxLeads   = 50,
    ) {}

    public static function fromRequest(array $data): self
    {
        return new self(
            name:       $data['name'],
            email:      $data['email'],
            password:   $data['password'],
            roleId:     $data['role_id'],
            phone:      $data['phone']      ?? null,
            department: $data['department'] ?? null,
            maxLeads:   $data['max_leads']  ?? 50,
        );
    }
}
