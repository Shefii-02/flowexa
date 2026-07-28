<?php

namespace App\Modules\Staff\DTOs;

// ─── Update Staff ─────────────────────────────────────────────────────────────
readonly class UpdateStaffDTO
{
    public function __construct(
        public ?string $name       = null,
        public ?int    $roleId     = null,
        public ?string $phone      = null,
        public ?string $department = null,
        public ?int    $maxLeads   = null,
        public ?bool   $isActive   = null,
    ) {}

    public static function fromRequest(array $data): self
    {
        return new self(
            name:       $data['name']       ?? null,
            roleId:     $data['role_id']    ?? null,
            phone:      $data['phone']      ?? null,
            department: $data['department'] ?? null,
            maxLeads:   $data['max_leads']  ?? null,
            isActive:   isset($data['is_active']) ? (bool) $data['is_active'] : null,
        );
    }
}
