<?php

namespace App\Modules\Staff\Http\Resources;

use App\Modules\Staff\DTOs\StaffPerformanceDTO;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;

// ─── Performance Resource ─────────────────────────────────────────────────────
class StaffPerformanceResource
{
    public static function collection(\Illuminate\Support\Collection $items): array
    {
        return $items->map(fn(StaffPerformanceDTO $dto) => [
            'id'               => $dto->userId,
            'name'             => $dto->name,
            'email'            => $dto->email,
            'department'       => $dto->department,
            'role'             => $dto->role,
            'leads'            => [
                'total'      => $dto->totalLeads,
                'new'        => $dto->newLeads,
                'contacted'  => $dto->contactedLeads,
                'follow_up'  => $dto->followUpLeads,
                'enrolled'   => $dto->enrolledLeads,
                'lost'       => $dto->lostLeads,
                'active'     => $dto->activeLeads,
            ],
            'conversion_rate'  => $dto->conversionRate,
            'capacity'         => [
                'active'  => $dto->activeLeads,
                'max'     => $dto->maxLeads,
                'percent' => $dto->capacityPercent,
            ],
        ])->values()->all();
    }
}
