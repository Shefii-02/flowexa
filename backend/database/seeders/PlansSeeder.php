<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * Single source of truth for the public subscription plans.
 *
 * Every plan is paid — there are no ₹0 plans. A new company instead gets a
 * 7-day free trial (Company.status = 'trial' + trial_ends_at) and only picks
 * a paid plan when the trial ends or they upgrade early.
 *
 * Idempotent: matches on `name` and re-applies every attribute on each run,
 * so editing a value here and re-running `db:seed --class=PlansSeeder`
 * updates the existing row in place. Any non-custom plan whose name is not in
 * the list below is deactivated (is_active = false) rather than deleted, so
 * companies still holding a retired plan keep working until they change plan.
 */
class PlansSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'name'           => 'Starter',
                'messages_limit' => 8000,
                'price'          => 499,
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
            ],
            [
                'name'           => 'Growth',
                'messages_limit' => 30000,
                'price'          => 1499,
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
            ],
            [
                'name'           => 'Pro',
                'messages_limit' => 100000,
                'price'          => 3999,
                'is_active'      => true,
                'features'       => [
                    'Up to 100,000 messages/month',
                    '5 WhatsApp Cloud numbers',
                    '10 WA Chat sessions',
                    'Full API access',
                    'Custom CRM integration',
                    'Dedicated account manager',
                    'Custom branding',
                ],
                'duration_type'         => 'monthly',
                'duration_months'       => 1,
                'alert_before_days'     => 10,
                'max_users'             => null,  // null == unlimited
                'max_templates'         => null,
                'max_phone_numbers'     => 5,
                'max_wa_sessions'       => 10,
                'max_campaigns'         => null,
                'max_contacts'          => null,
                'max_labels'            => null,
                'max_flow_nodes'        => null,
                'max_campaign_contacts' => null,
                'throttle_per_minute'   => 500,
            ],
        ];

        $keep = [];
        foreach ($plans as $plan) {
            Plan::updateOrCreate(['name' => $plan['name']], $plan);
            $keep[] = $plan['name'];
        }

        // Retire every other non-custom plan (e.g. the legacy ₹0 "Trial" plan)
        // without deleting it — companies still on it keep working.
        $retired = Plan::where('is_custom', false)
            ->whereNotIn('name', $keep)
            ->where('is_active', true)
            ->update(['is_active' => false]);

        $this->command->info('✅ Plans upserted: ' . implode(', ', $keep)
            . ($retired ? " · {$retired} legacy plan(s) retired" : ''));
    }
}
