<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Plan;
use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $superAdminRole = Role::where('name', 'superadmin')->firstOrFail();
        $trialPlan      = Plan::where('name', 'Trial')->firstOrFail();

        // ── Platform company (for superadmin) ─────────────────────────────────
        $platform = Company::firstOrCreate(
            ['slug' => 'platform'],
            [
                'plan_id'       => $trialPlan->id,
                'name'          => 'WA SaaS Platform',
                'app_id'        => 'WA_APP_PLATFORM_0001',
                'private_token' => encrypt(Str::random(40)),
                'email'         => 'platform@waapi.com',
                'status'        => 'active',
            ]
        );

        // Create wallet if not exists
        if (!$platform->wallet) {
            Wallet::create([
                'company_id' => $platform->id,
                'balance'    => 999999,
            ]);
        }

        // ── SuperAdmin user ────────────────────────────────────────────────────
        $superAdmin = User::firstOrCreate(
            ['email' => 'superadmin@waapi.com'],
            [
                'company_id' => $platform->id,
                'role_id'    => $superAdminRole->id,
                'name'       => 'Super Admin',
                'password'   => Hash::make('SuperAdmin@123'),
                'is_active'  => true,
            ]
        );

        $this->command->info('');
        $this->command->info('✅ SuperAdmin created:');
        $this->command->info('   Email:    superadmin@waapi.com');
        $this->command->info('   Password: SuperAdmin@123');
        $this->command->warn('   ⚠️  Change this password immediately in production!');
        $this->command->info('');
    }
}
