<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Every seeder here is idempotent (create-or-update), so `php artisan db:seed`
     * is safe to re-run. Order matters: plans and system roles must exist before
     * the seeders that reference them.
     *
     * Not included: LeadCategorySeeder — it has no run(); it's a per-company
     * helper (`LeadCategorySeeder::seedForCompany($id)`) invoked during company
     * onboarding, not global seeding.
     */
    public function run(): void
    {
        $this->call([
            // ── Plans & limits ───────────────────────────────────────────────
            PlansSeeder::class,
            UpdatePlansWithLimitsSeeder::class, // redundant with PlansSeeder; kept for completeness

            // ── Roles & permissions ──────────────────────────────────────────
            RolesSeeder::class,
            SuperadminStaffRoleSeeder::class,
            PermissionsSeeder::class,

            // ── Platform account ─────────────────────────────────────────────
            SuperAdminSeeder::class,

            // ── Per-company default roles + user role backfill ───────────────
            CompanyRolesSeeder::class,

            // ── Billing ──────────────────────────────────────────────────────
            TopupPackagesSeeder::class,

            // ── Shared content libraries ─────────────────────────────────────
            PrebuiltTemplateSeeder::class,
            AgentPlaybookTemplateSeeder::class,
            MetaAdsSeeder::class,
        ]);
    }
}
