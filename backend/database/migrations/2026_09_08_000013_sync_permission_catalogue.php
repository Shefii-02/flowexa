<?php

use App\Models\Permission;
use App\Models\Role;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Make sure every permission in the canonical catalogue
 * (PermissionsSeeder::PERMISSIONS) exists in the `permissions` table, and
 * re-sync the built-in system roles' permission JSON + role_permissions pivot.
 *
 * Runs the same logic as PermissionsSeeder so the DB is always in step after a
 * deploy without needing `db:seed`.
 */
return new class extends Migration {
    public function up(): void
    {
        // 1. Upsert the catalogue.
        foreach (PermissionsSeeder::PERMISSIONS as $perm) {
            Permission::updateOrCreate(['key' => $perm['key']], $perm);
        }

        // 2. Re-sync system roles for every company.
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

            // merge — never remove a key an admin explicitly added
            $merged = array_values(array_unique(array_merge($role->permissions ?? [], $validKeys)));
            $role->update(['permissions' => $merged]);
        });
    }

    public function down(): void
    {
        // catalogue rows are safe to keep
    }
};
