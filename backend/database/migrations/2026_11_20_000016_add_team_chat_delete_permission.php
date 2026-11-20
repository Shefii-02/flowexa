<?php

use App\Models\Permission;
use App\Models\Plan;
use App\Models\Role;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Deleting a team chat (1:1 or group) for everyone now needs its own permission, team_chat.delete. Until now any group admin could
 * delete their group. To keep deletion with the people who could already do it at company level, the key is granted to the owner and
 * to roles that moderate team chat (team_chat.moderate); give it to other roles on the Roles screen. Plans with an explicit
 * permission list get the key too.
 */
return new class extends Migration {
    private const KEY = 'team_chat.delete';

    public function up(): void
    {
        foreach (PermissionsSeeder::PERMISSIONS as $perm) {
            if ($perm['key'] === self::KEY) { Permission::updateOrCreate(['key' => $perm['key']], $perm); }
        }
        $id = Permission::where('key', self::KEY)->value('id');

        Role::query()->get()->each(function (Role $role) use ($id) {
            $have = $role->permissions ?? [];
            if ($role->name !== 'owner' && !in_array('team_chat.moderate', $have, true)) { return; }
            $role->permissionRelations()->syncWithoutDetaching([$id]);
            $role->update(['permissions' => array_values(array_unique(array_merge($have, [self::KEY])))]);
        });

        Plan::query()->whereNotNull('permissions')->get()->each(function (Plan $plan) {
            $merged = array_values(array_unique(array_merge($plan->permissions, [self::KEY])));
            if ($merged !== array_values($plan->permissions)) { $plan->forceFill(['permissions' => $merged])->save(); }
        });
    }

    public function down(): void
    {
        Permission::where('key', self::KEY)->delete();
    }
};
