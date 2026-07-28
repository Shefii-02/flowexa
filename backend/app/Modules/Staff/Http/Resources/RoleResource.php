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
            'id'          => $this->id,
            'name'        => $this->name,
            'label'       => $this->label,
            'permissions' => $this->permissions,
            'is_system'   => $this->is_system,
        ];
    }
}
