<?php

use App\Models\Permission;
use App\Models\Plan;
use App\Models\Role;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Team chat becomes a plan feature (module "team_chat", also buyable as an add-on) with its own permissions. Until now every active
 * staff member could use it and anyone with staff.manage moderated it. So nobody loses it on deploy:
 *
 *   team_chat.view       every role        use the chat
 *   team_chat.groups     every role        create / manage groups
 *   team_chat.moderate   roles with staff.manage, and the owner      reports + every group
 *
 * Plans with an explicit feature list keep Team chat (add it to them) — remove it from a plan to take it away; plans with an explicit
 * permission list get the three keys.
 */
return new class extends Migration {
    private const KEYS = ['team_chat.view', 'team_chat.groups', 'team_chat.moderate'];

    public function up(): void
    {
        foreach (PermissionsSeeder::PERMISSIONS as $perm) {
            if (in_array($perm['key'], self::KEYS, true)) {
                Permission::updateOrCreate(['key' => $perm['key']], $perm);
            }
        }
        $ids = Permission::whereIn('key', self::KEYS)->pluck('id', 'key');

        Role::query()->get()->each(function (Role $role) use ($ids) {
            $have = $role->permissions ?? [];
            $grant = ['team_chat.view', 'team_chat.groups'];
            if ($role->name === 'owner' || in_array('staff.manage', $have, true)) {
                $grant[] = 'team_chat.moderate';
            }
            $role->permissionRelations()->syncWithoutDetaching($ids->only($grant)->values()->all());
            $role->update(['permissions' => array_values(array_unique(array_merge($have, $grant)))]);
        });

        Plan::query()->get()->each(function (Plan $plan) {
            $changes = [];
            if ($plan->modules !== null && !in_array('team_chat', $plan->modules, true)) {
                $changes['modules'] = array_values(array_merge($plan->modules, ['team_chat']));
            }
            if ($plan->permissions !== null) {
                $merged = array_values(array_unique(array_merge($plan->permissions, self::KEYS)));
                if ($merged !== array_values($plan->permissions)) { $changes['permissions'] = $merged; }
            }
            if ($changes) { $plan->forceFill($changes)->save(); }
        });
    }

    public function down(): void
    {
        Permission::whereIn('key', self::KEYS)->delete();
    }
};
