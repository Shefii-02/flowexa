<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const KEYS = [
        ['key' => 'accounts.read',   'label' => 'View Accounts & GST',   'group' => 'Accounts', 'type' => 'viewer', 'sort_order' => 55],
        ['key' => 'accounts.manage', 'label' => 'Manage Accounts & GST', 'group' => 'Accounts', 'type' => 'manage', 'sort_order' => 56],
    ];

    /** Owner and Admin roles (system and per-company) get both keys — owners bypass the server check, but the UI reads the role's list. */
    public function up(): void
    {
        DB::transaction(function () {
            $ids = [];
            foreach (self::KEYS as $p) { $ids[] = Permission::updateOrCreate(['key' => $p['key']], $p)->id; }

            foreach (Role::whereIn('name', ['owner', 'Owner', 'admin', 'Admin'])->get() as $role) {
                $role->permissionRelations()->syncWithoutDetaching($ids);
                $role->update(['permissions' => array_values(array_unique(array_merge($role->permissions ?? [], array_column(self::KEYS, 'key'))))]);
            }
        });
    }

    public function down(): void
    {
        $ids = Permission::whereIn('key', array_column(self::KEYS, 'key'))->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        Permission::whereIn('id', $ids)->delete();
    }
};
