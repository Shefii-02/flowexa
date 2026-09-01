<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    protected $fillable = [
        'company_id', 'name', 'label', 'description', 'color',
        'permissions', 'is_system', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'permissions' => 'array',
        'is_system'   => 'boolean',
        'is_active'   => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    // Normalized permissions via role_permissions pivot
    public function permissionRelations(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions');
    }

    // ── Permission helpers ────────────────────────────────────────────────────
    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissions ?? []);
    }

    public function hasAnyPermission(array $permissions): bool
    {
        return !empty(array_intersect($permissions, $this->permissions ?? []));
    }

    // Sync both the pivot table and the JSON column atomically
    public function syncPermissions(array $permissionIds): void
    {
        $this->permissionRelations()->sync($permissionIds);
        $keys = Permission::whereIn('id', $permissionIds)->pluck('key')->toArray();
        $this->update(['permissions' => $keys]);
    }
}
