<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * Starter plans for a brand-new install ONLY.
 *
 * Plans are managed from Superadmin → Plans (create, edit, soft-delete). This seeder therefore never edits or retires
 * anything: it inserts the two example plans when the plans table has no rows at all (soft-deleted ones count), and
 * does nothing otherwise — so deleting a seeded plan in the UI sticks, and re-running the seeder is harmless.
 */
class PlansSeeder extends Seeder
{
    public function run(): void
    {
        if (Plan::withTrashed()->exists()) {
            $this->command?->info('Plans already exist — nothing seeded. Manage them in Superadmin → Plans.');
            return;
        }

        $plans = [
            [
                'name'           => 'Starter',
                'messages_limit' => 8000,
                'price'          => 499,
                'price_yearly'   => 4990,
                'is_active'      => true,
                'features'       => [
                    'Up to 8,000 messages/month',
                    '1 WhatsApp Cloud number',
                    '1 WA Chat session',
                    'Flow builder',
                    'Contact management',
                    'Basic analytics',
                    'Email support',
                ],
                'modules'        => ['media_library', 'short_links', 'sticky_notes', 'todo', 'wa_chat', 'wa_cloud', 'crm', 'leads'],
                'lead_assign_methods' => ['manual', 'round_robin'],
                'max_media_storage_mb' => 1024, 'max_campaigns_per_month' => 10, 'max_surveys' => 5, 'max_short_links' => 50,
                'duration_type'         => 'monthly',
                'duration_months'       => 1,
                'alert_before_days'     => 7,
                'max_users'             => 5,
                'max_templates'         => 15,
                'max_phone_numbers'     => 1,
                'max_wa_sessions'       => 1,
                'max_campaigns'         => 15,
                'max_contacts'          => 3000,
                'max_labels'            => 20,
                'max_flow_nodes'        => 40,
                'max_campaign_contacts' => 3000,
                'throttle_per_minute'   => 60,
                'max_leads_per_month'      => 500,
                'max_lead_categories'      => 10,
                'max_roles'                => 5,
                'max_instagram_accounts'   => 1,
                'max_meta_ads_accounts'    => 1,
                'max_website_widgets'      => 1,
                'google_sheets_enabled'      => true,
                'google_drive_enabled'       => true,
                'calendar_enabled'           => true,
                'email_integration_enabled'  => true,
            ],
            [
                'name'           => 'Growth',
                'messages_limit' => 30000,
                'price'          => 1499,
                'price_yearly'   => 14990,
                'is_active'      => true,
                'features'       => [
                    'Up to 30,000 messages/month',
                    '3 WhatsApp Cloud numbers',
                    '3 WA Chat sessions',
                    'Flow builder + AI suggestions',
                    'CRM integration',
                    'Advanced analytics',
                    'Priority support',
                    'OTP API access',
                ],
                'modules'        => ['media_library', 'short_links', 'sticky_notes', 'todo', 'integrations', 'wa_chat', 'wa_cloud', 'ai_agent', 'crm', 'leads', 'social_dm', 'web_widgets'],
                'lead_assign_methods' => ['manual', 'round_robin', 'algorithm', 'uber', 'auto'],
                'max_media_storage_mb' => 10240, 'max_campaigns_per_month' => 60, 'max_surveys' => 25, 'max_short_links' => 500,
                'duration_type'         => 'monthly',
                'duration_months'       => 1,
                'alert_before_days'     => 7,
                'max_users'             => 15,
                'max_templates'         => 40,
                'max_phone_numbers'     => 3,
                'max_wa_sessions'       => 3,
                'max_campaigns'         => 50,
                'max_contacts'          => 20000,
                'max_labels'            => 80,
                'max_flow_nodes'        => 150,
                'max_campaign_contacts' => 20000,
                'throttle_per_minute'   => 150,
                'max_leads_per_month'      => 2000,
                'max_lead_categories'      => 30,
                'max_roles'                => 15,
                'max_instagram_accounts'   => 3,
                'max_meta_ads_accounts'    => 3,
                'max_website_widgets'      => 3,
                'google_sheets_enabled'      => true,
                'google_drive_enabled'       => true,
                'calendar_enabled'           => true,
                'email_integration_enabled'  => true,
            ],
        ];

        foreach ($plans as $plan) {
            Plan::create($plan);
        }

        $this->command?->info('✅ Example plans created: ' . implode(', ', array_column($plans, 'name')));
    }
}
