<?php

namespace App\Modules\Staff\DTOs;


// ─── Staff Performance ────────────────────────────────────────────────────────
readonly class StaffPerformanceDTO
{
    public function __construct(
        public int    $userId,
        public string $name,
        public string $email,
        public ?string $department,
        public ?string $role,
        public int    $totalLeads,
        public int    $newLeads,
        public int    $contactedLeads,
        public int    $followUpLeads,
        public int    $enrolledLeads,
        public int    $lostLeads,
        public float  $conversionRate,
        public int    $activeLeads,
        public int    $maxLeads,
        public float  $capacityPercent,
    ) {}
}
