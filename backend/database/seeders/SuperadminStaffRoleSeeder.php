<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\Plan;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

// ════════════════════════════════════════════════════════════════════════════
// Superadmin Staff Role
// ════════════════════════════════════════════════════════════════════════════
class SuperadminStaffRoleSeeder extends Seeder
{
    public function run(): void
    {
        Role::firstOrCreate(
            ['name' => 'superadmin_staff'],
            [
                'label'       => 'Platform Staff',
                'is_system'   => true,
                'permissions' => [
                    'platform.view_companies',
                    'platform.manage_companies',
                    'platform.view_reports',
                    'platform.topup_wallets',
                    'platform.manage_plans',
                    'platform.view_analytics',
                ],
            ]
        );

        $this->command->info('✅ Superadmin staff role seeded');
    }
}
