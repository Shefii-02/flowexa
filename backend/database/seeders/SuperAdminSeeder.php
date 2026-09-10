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
        $superAdminRole = Role::whereNull('company_id')->where('name', 'superadmin')->firstOrFail();
        $trialPlan      = Plan::where('name', 'Trial')->firstOrFail();

        // ── Platform company (host of the superadmin) ─────────────────────────
        // `app_id` / `private_token` are NOT NULL secrets — generated once on
        // create and never rotated. The descriptive fields are refreshed on
        // every run.
        $refreshable = [
            'plan_id' => $trialPlan->id,
            'name'    => 'WA SaaS Platform',
            'email'   => 'platform@waapi.com',
            'status'  => 'active',
        ];

        $platform = Company::withTrashed()->firstOrCreate(
            ['slug' => 'platform'],
            $refreshable + [
                'app_id'        => 'WA_APP_PLATFORM_0001',
                'private_token' => encrypt(Str::random(40)),
            ]
        );
        if ($platform->trashed()) {
            $platform->restore();
        }
        $platform->update($refreshable);

        // Wallet — created once; the balance is never reset by the seeder.
        Wallet::firstOrCreate(
            ['company_id' => $platform->id],
            ['balance' => 999999]
        );

        // ── SuperAdmin user ──────────────────────────────────────────────────
        // `password` is NOT NULL — set once on create, never touched again.
        $superAdmin = User::withTrashed()->firstOrCreate(
            ['email' => 'superadmin@waapi.com'],
            [
                'company_id' => $platform->id,
                'role_id'    => $superAdminRole->id,
                'name'       => 'Super Admin',
                'is_active'  => true,
                'password'   => Hash::make('SuperAdmin@123'),
            ]
        );
        if ($superAdmin->trashed()) {
            $superAdmin->restore();
        }

        // Refresh the safe fields. `company_id` is only corrected when it points
        // nowhere valid — never yank an existing superadmin off a real company.
        $refresh = [
            'role_id'   => $superAdminRole->id,
            'name'      => 'Super Admin',
            'is_active' => true,
        ];
        if (blank($superAdmin->company_id) || ! Company::whereKey($superAdmin->company_id)->exists()) {
            $refresh['company_id'] = $platform->id;
        }
        $superAdmin->update($refresh);

        if ($superAdmin->wasRecentlyCreated) {
            $this->command->info('');
            $this->command->info('✅ SuperAdmin created:');
            $this->command->info('   Email:    superadmin@waapi.com');
            $this->command->info('   Password: SuperAdmin@123');
            $this->command->warn('   ⚠️  Change this password immediately in production!');
            $this->command->info('');
        } else {
            $this->command->info('✅ SuperAdmin present (superadmin@waapi.com) — password left untouched.');
        }
    }
}
