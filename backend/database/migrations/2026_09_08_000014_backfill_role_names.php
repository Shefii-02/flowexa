<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Str;

/**
 * Every role in this deployment lost its `name` (the machine key that
 * `User::isSuperAdmin()` / `isOwner()` and all permission checks rely on) —
 * only `label` survived. Rebuild `name` from `label`.
 */
return new class extends Migration {
    private const MAP = [
        'Super Admin'    => 'superadmin',
        'Platform Staff' => 'superadmin_staff',
        'Owner'          => 'owner',
        'Admin'          => 'admin',
        'Team Lead'      => 'team_lead',
        'Team Leader'    => 'team_lead',
        'Counsellor'     => 'counsellor',
        'Manager'        => 'manager',
        'Sales Agent'    => 'sales_agent',
        'Support Agent'  => 'support_agent',
        'Viewer'         => 'viewer',
    ];

    public function up(): void
    {
        Role::query()->get()->each(function (Role $role) {
            if (filled($role->name)) {
                return;
            }
            $name = self::MAP[$role->label] ?? Str::snake(Str::lower(trim((string) $role->label)));
            if ($name === '') {
                $name = 'role_' . $role->id;
            }
            $role->forceFill(['name' => $name])->saveQuietly();
        });
    }

    public function down(): void
    {
        // keep the repaired names
    }
};
