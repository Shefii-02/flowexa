<?php

use App\Models\Permission;
use App\Models\Role;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * "Pages open to everyone" (My Attendance, To-do, Dashboard, Support …) become tick-based permissions (`pages.*`) so a role or a single
 * person can be allowed / blocked page by page. Every existing role gets all of them, so nobody loses a page on deploy.
 */
return new class extends Migration {
    public function up(): void
    {
        $keys = [];
        foreach (PermissionsSeeder::PERMISSIONS as $perm) {
            if (str_starts_with($perm['key'], 'pages.')) {
                Permission::updateOrCreate(['key' => $perm['key']], $perm);
                $keys[] = $perm['key'];
            }
        }
        $ids = Permission::whereIn('key', $keys)->pluck('id')->all();

        Role::query()->get()->each(function (Role $role) use ($keys, $ids) {
            $role->permissionRelations()->syncWithoutDetaching($ids);
            $role->update(['permissions' => array_values(array_unique(array_merge($role->permissions ?? [], $keys)))]);
        });
    }

    public function down(): void
    {
        Permission::where('key', 'like', 'pages.%')->delete();
    }
};
