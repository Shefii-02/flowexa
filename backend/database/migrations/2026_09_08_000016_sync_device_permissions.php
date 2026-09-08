<?php

use App\Models\Permission;
use App\Models\Role;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Adds the `devices.view` / `devices.manage` permissions (phone-app linked-device
 * management) to the catalogue and re-syncs the built-in system roles so
 * owner / admin / superadmin / team_lead pick them up without a full db:seed.
 *
 * Same idempotent logic as PermissionsSeeder / the 000013 catalogue sync.
 */
return new class extends Migration {
    public function up(): void
    {
        foreach (PermissionsSeeder::PERMISSIONS as $perm) {
            Permission::updateOrCreate(['key' => $perm['key']], $perm);
        }

        $permMap = Permission::pluck('id', 'key');
        $allKeys = $permMap->keys()->all();
        $roleDefs = (new PermissionsSeeder())->rolePermissions($allKeys);

        Role::query()->where('is_system', true)->get()->each(function (Role $role) use ($roleDefs, $permMap) {
            $keys = $roleDefs[$role->name] ?? null;
            if ($keys === null) {
                return;
            }
            $validKeys = array_values(array_unique(array_filter($keys, fn ($k) => $permMap->has($k))));
            $role->permissionRelations()->sync(array_values($permMap->only($validKeys)->all()));

            $merged = array_values(array_unique(array_merge($role->permissions ?? [], $validKeys)));
            $role->update(['permissions' => $merged]);
        });
    }

    public function down(): void
    {
        Permission::whereIn('key', ['devices.view', 'devices.manage'])->delete();
    }
};
