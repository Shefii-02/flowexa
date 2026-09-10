<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Services\CompanySetupService;
use Illuminate\Database\Seeder;

/**
 * Per-company roles & user role assignment.
 *
 *  1. For every company, (re)creates the default roles (Admin / Manager /
 *     Sales Agent / Support Agent / Viewer) and refreshes their permissions
 *     from {@see CompanySetupService::syncDefaultRoles()} — idempotent.
 *
 *  2. Backfills `users.role_id` for anyone missing a role:
 *       - the company's own contact email  → system `owner` role
 *       - everyone else                     → the company's `Viewer` role
 *         (least privilege), falling back to the system `viewer` role.
 *
 * Never touches a user who already has a role.
 */
class CompanyRolesSeeder extends Seeder
{
    public function run(): void
    {
        /** @var CompanySetupService $setup */
        $setup = app(CompanySetupService::class);

        $companies = Company::all();
        foreach ($companies as $company) {
            $setup->syncDefaultRoles($company);
        }
        $this->command->info("✅ Default roles synced for {$companies->count()} companies.");

        $ownerRole  = Role::whereNull('company_id')->where('name', 'owner')->first();
        $viewerRole = Role::whereNull('company_id')->where('name', 'viewer')->first();

        $assigned = 0;
        User::withTrashed()->whereNull('role_id')->with('company')->chunkById(200, function ($users) use (&$assigned, $ownerRole, $viewerRole) {
            foreach ($users as $user) {
                $isOwner = $user->company
                    && $user->company->email
                    && strcasecmp($user->company->email, (string) $user->email) === 0;

                $role = $isOwner
                    ? $ownerRole
                    : (Role::where('company_id', $user->company_id)->where('name', 'Viewer')->first() ?? $viewerRole);

                if ($role) {
                    $user->forceFill(['role_id' => $role->id])->saveQuietly();
                    $assigned++;
                }
            }
        });

        $this->command->info("✅ Assigned a role to {$assigned} user(s) that had none.");
    }
}
