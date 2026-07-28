<?php

namespace App\Modules\Staff\Http\Resources;

use App\Modules\Staff\DTOs\StaffPerformanceDTO;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;

// ─── Single Staff Resource ────────────────────────────────────────────────────
class StaffResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'name'            => $this->name,
            'email'           => $this->email,
            'phone'           => $this->phone,
            'avatar'          => $this->avatar,
            'department'      => $this->department,
            'is_active'       => $this->is_active,
            'max_leads'       => $this->max_leads,
            'last_login'      => $this->last_login_at?->toIso8601String(),
            'created_at'      => $this->created_at->toIso8601String(),

            // Lead counts (from withCount)
            'total_leads'     => $this->total_leads   ?? 0,
            'active_leads'    => $this->active_leads  ?? 0,
            'enrolled_leads'  => $this->enrolled_leads ?? 0,

            // Capacity
            'capacity_percent' => $this->max_leads > 0
                ? round((($this->active_leads ?? 0) / $this->max_leads) * 100, 1)
                : 0,

            // Role
            'role' => $this->whenLoaded('role', fn() => [
                'id'          => $this->role->id,
                'name'        => $this->role->name,
                'label'       => $this->role->label,
                'permissions' => $this->role->permissions,
            ]),
        ];
    }
}



