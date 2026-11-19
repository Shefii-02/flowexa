<?php

use App\Models\Permission;
use App\Models\Plan;
use App\Models\Role;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Calendar bookings used to be visible to — and changeable by — every staff member. Now a role sees and manages only its own
 * bookings unless it holds calendar.view_all / calendar.manage_all. So nobody loses sight of the team calendar on deploy:
 *
 *   calendar.view_all     roles with leads.view_all, hr.manage or staff.manage     (team leader and above)
 *   calendar.manage_all   those that can also manage (leads.manage + view_all, hr.manage or staff.manage)
 *
 * Plans with an explicit permission list would hide the new keys, so they are added to plans that include the Tasks feature.
 */
return new class extends Migration {
    private const NEW = ['calendar.view_all', 'calendar.manage_all'];

    public function up(): void
    {
        foreach (PermissionsSeeder::PERMISSIONS as $perm) {
            if (in_array($perm['key'], self::NEW, true)) {
                Permission::updateOrCreate(['key' => $perm['key']], $perm);
            }
        }
        $ids = Permission::whereIn('key', self::NEW)->pluck('id', 'key');

        Role::query()->get()->each(function (Role $role) use ($ids) {
            $has = fn (string ...$k) => count(array_intersect($k, $role->permissions ?? [])) > 0;
            $grant = [];
            if ($has('leads.view_all', 'hr.manage', 'staff.manage')) { $grant[] = 'calendar.view_all'; }
            if (($has('leads.manage') && $has('leads.view_all')) || $has('hr.manage', 'staff.manage')) { $grant[] = 'calendar.manage_all'; }
            if (!$grant) {
                return;
            }
            $role->permissionRelations()->syncWithoutDetaching($ids->only($grant)->values()->all());
            $role->update(['permissions' => array_values(array_unique(array_merge($role->permissions ?? [], $grant)))]);
        });

        Plan::query()->whereNotNull('permissions')->get()->each(function (Plan $plan) {
            if ($plan->modules !== null && !in_array('tasks', $plan->modules, true)) {
                return;   // the plan has no Tasks feature, so these permissions are not part of it
            }
            $merged = array_values(array_unique(array_merge($plan->permissions ?? [], self::NEW)));
            if ($merged !== array_values($plan->permissions ?? [])) {
                $plan->forceFill(['permissions' => $merged])->save();
            }
        });
    }

    public function down(): void
    {
        Permission::whereIn('key', self::NEW)->delete();
    }
};
