<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

// ════════════════════════════════════════════════════════════════════════════
// Update Plans with limits
//
// The per-plan limits now live in {@see PlansSeeder} (single source of truth).
// This seeder is kept for backwards compatibility — anything that calls it
// directly still gets a correct, idempotent upsert.
// ════════════════════════════════════════════════════════════════════════════
class UpdatePlansWithLimitsSeeder extends Seeder
{
    public function run(): void
    {
        $limits = [
            'Trial' => [
                'duration_type'         => 'custom',
                'duration_months'       => null,
                'max_users'             => 3,
                'max_templates'         => 5,
                'max_phone_numbers'     => 1,
                'max_campaigns'         => 5,
                'max_contacts'          => 500,
                'max_labels'            => 10,
                'max_flow_nodes'        => 20,
                'max_campaign_contacts' => 500,
                'throttle_per_minute'   => 20,
                // Every new company lands on this plan (see AuthRepository::createCompanyWithOwner).
                // Kept at the minimum viable value (1) for every new count limit, except leads —
                // 1 lead/month would make it impossible to evaluate the lead pipeline during a trial.
                'max_leads_per_month'      => 50,
                'max_lead_categories'      => 1,
                'max_roles'                => 1,
                'max_instagram_accounts'   => 1,
                'max_meta_ads_accounts'    => 1,
                'max_website_widgets'      => 1,
                'google_sheets_enabled'      => true,
                'google_drive_enabled'       => true,
                'calendar_enabled'           => true,
                'email_integration_enabled'  => true,
            ],
            'Starter' => [
                'duration_type'         => 'monthly',
                'duration_months'       => 1,
                'max_users'             => 10,
                'max_templates'         => 20,
                'max_phone_numbers'     => 1,
                'max_campaigns'         => 20,
                'max_contacts'          => 5000,
                'max_labels'            => 25,
                'max_flow_nodes'        => 50,
                'max_campaign_contacts' => 5000,
                'throttle_per_minute'   => 60,
            ],
            'Growth' => [
                'duration_type'         => 'monthly',
                'duration_months'       => 1,
                'max_users'             => 25,
                'max_templates'         => 50,
                'max_phone_numbers'     => 3,
                'max_campaigns'         => 50,
                'max_contacts'          => 25000,
                'max_labels'            => 100,
                'max_flow_nodes'        => 200,
                'max_campaign_contacts' => 25000,
                'throttle_per_minute'   => 150,
            ],
            'Enterprise' => [
                'duration_type'         => 'monthly',
                'duration_months'       => 1,
                'max_users'             => null,  // unlimited
                'max_templates'         => null,
                'max_phone_numbers'     => 5,
                'max_campaigns'         => null,
                'max_contacts'          => null,
                'max_labels'            => null,
                'max_flow_nodes'        => null,
                'max_campaign_contacts' => null,
                'throttle_per_minute'   => 500,
                'max_leads_per_month'      => null,  // unlimited
                'max_lead_categories'      => null,
                'max_roles'                => null,
                'max_instagram_accounts'   => null,
                'max_meta_ads_accounts'    => null,
                'max_website_widgets'      => null,
                'google_sheets_enabled'      => true,
                'google_drive_enabled'       => true,
                'calendar_enabled'           => true,
                'email_integration_enabled'  => true,
            ],
        ];

        foreach ($limits as $name => $data) {
            Plan::updateOrCreate(['name' => $name], $data);
        }

        $this->command->info('✅ Plan limits upserted');
    }
}
