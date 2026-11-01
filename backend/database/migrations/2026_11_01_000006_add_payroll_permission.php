<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const KEY = ['key' => 'accounts.payroll', 'label' => 'Manage Payroll & Salaries', 'group' => 'Accounts', 'type' => 'manage', 'sort_order' => 57];

    /** Salaries are sensitive, so payroll has its own key. Owner and Admin roles get it; everyone else must be granted it. */
    public function up(): void
    {
        DB::transaction(function () {
            $id = Permission::updateOrCreate(['key' => self::KEY['key']], self::KEY)->id;
            foreach (Role::whereIn('name', ['owner', 'Owner', 'admin', 'Admin'])->get() as $role) {
                $role->permissionRelations()->syncWithoutDetaching([$id]);
                $role->update(['permissions' => array_values(array_unique(array_merge($role->permissions ?? [], [self::KEY['key']])))]);
            }
        });
    }

    public function down(): void
    {
        $id = Permission::where('key', self::KEY['key'])->value('id');
        if ($id) { DB::table('role_permissions')->where('permission_id', $id)->delete(); Permission::whereKey($id)->delete(); }
    }
};
