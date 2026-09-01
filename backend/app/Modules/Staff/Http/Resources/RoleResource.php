<?php

namespace App\Modules\Staff\Http\Resources;

use App\Modules\Staff\DTOs\StaffPerformanceDTO;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;

// ─── Role Resource ────────────────────────────────────────────────────────────
class RoleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'name'           => $this->name,
            'label'          => $this->label ?? $this->name,
            'description'    => $this->description,
            'color'          => $this->color ?? '#6366f1',
            'is_system'      => (bool) $this->is_system,
            'is_active'      => (bool) ($this->is_active ?? true),
            'sort_order'     => (int) ($this->sort_order ?? 0),
            'company_id'     => $this->company_id,
            'users_count'    => $this->users_count ?? $this->whenCounted('users'),
            'permissions'    => $this->permissions ?? [],
            'permission_ids' => $this->whenLoaded('permissionRelations',
                fn() => $this->permissionRelations->pluck('id')->toArray(),
                []
            ),
        ];
    }
}
