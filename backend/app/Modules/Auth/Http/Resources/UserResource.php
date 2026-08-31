<?php

namespace App\Modules\Auth\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'name'       => $this->name,
            'email'      => $this->email,
            'phone'      => $this->phone,
            'avatar'     => $this->avatar,
            'department' => $this->department,
            'is_active'  => $this->is_active,
            'last_login' => $this->last_login_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),

            // Role with permissions
            'role' => $this->whenLoaded('role', fn() => [
                'id'          => $this->role->id,
                'name'        => $this->role->name,
                'label'       => $this->role->label,
                'permissions' => $this->role->permissions,
            ]),

            // Company (only when loaded)
            'company' => $this->whenLoaded('company', fn() =>
                new CompanyResource($this->company)
            ),

        ];
    }
}
