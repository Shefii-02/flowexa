<?php

use App\Models\Permission;
use App\Models\Role;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Adds the permissions behind lead verification, lead recall and sales-activity monitoring to the catalogue,
 * and grants them to the roles that could already do the closest thing — so nobody loses access on deploy,
 * and an admin can then hand them out (or take them away) per role under Roles & permissions.
 *
 *   leads.recall                roles that had leads.manage          (recall was a leads.manage action)
 *   leads.verify                roles with leads.manage + leads.view_all   (team leader and above — it approves other people's work)
 *   hr.sales_activity.view      roles with leads.view_all / leads.manage / hr.manage   (the old "monitor" gate)
 *   hr.sales_activity.manage    roles with leads.manage / hr.manage                    (the old "set targets" gate)
 */
return new class extends Migration {
    private const NEW = ['leads.verify', 'leads.recall', 'hr.sales_activity.view', 'hr.sales_activity.manage'];

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
            if ($has('leads.manage')) { $grant[] = 'leads.recall'; }
            if ($has('leads.manage') && $has('leads.view_all')) { $grant[] = 'leads.verify'; }
            if ($has('leads.view_all', 'leads.manage', 'hr.manage')) { $grant[] = 'hr.sales_activity.view'; }
            if ($has('leads.manage', 'hr.manage')) { $grant[] = 'hr.sales_activity.manage'; }
            if (!$grant) {
                return;
            }
            $role->permissionRelations()->syncWithoutDetaching($ids->only($grant)->values()->all());
            $role->update(['permissions' => array_values(array_unique(array_merge($role->permissions ?? [], $grant)))]);
        });
    }

    public function down(): void
    {
        Permission::whereIn('key', self::NEW)->delete();   // role_permissions rows cascade; the JSON column keeps harmless unknown keys
    }
};
