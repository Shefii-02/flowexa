<?php

use App\Models\Permission;
use App\Models\Plan;
use App\Models\Role;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Website live chats (permission widget_chat.handle) and the chat review panel (widget_chat.review) were added to the seeder only, so roles
 * that already existed never received them — the owner of an existing company saw a blank / "no access" Website chats page.
 *
 *   widget_chat.handle   owner, admin, and any role that can manage leads or staff (team leads, counsellors, sales)
 *   widget_chat.review   owner, admin, and roles with staff.manage
 *
 * Plans with an explicit permission list get both keys so the plan does not filter them out.
 */
return new class extends Migration {
    private const KEYS = ['widget_chat.handle', 'widget_chat.review'];

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
            $head = in_array($role->name, ['owner', 'admin'], true) || in_array('staff.manage', $have, true);
            $grant = [];
            if ($head || in_array('leads.manage', $have, true) || in_array('inbox.manage', $have, true)) { $grant[] = 'widget_chat.handle'; }
            if ($head) { $grant[] = 'widget_chat.review'; }
            if (! $grant) { return; }
            $role->permissionRelations()->syncWithoutDetaching($ids->only($grant)->values()->all());
            $role->update(['permissions' => array_values(array_unique(array_merge($have, $grant)))]);
        });

        Plan::query()->get()->each(function (Plan $plan) {
            if ($plan->permissions === null) { return; }
            $merged = array_values(array_unique(array_merge($plan->permissions, self::KEYS)));
            if ($merged !== array_values($plan->permissions)) { $plan->forceFill(['permissions' => $merged])->save(); }
        });
    }

    public function down(): void
    {
        // Permissions stay: removing them would also strip access the seeder grants to new roles.
    }
};
