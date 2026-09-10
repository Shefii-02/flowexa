<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * Single source of truth for the public subscription plans.
 *
 * Idempotent: matches on `name` and re-applies every attribute (pricing,
 * feature list, and the per-plan usage limits) on each run, so editing a
 * value here and re-running `db:seed` updates the existing row in place.
 */
class PlansSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'name'           => 'Trial',
                'messages_limit' => 1000,
                'price'          => 0,
                'is_active'      => true,
                'features'       => [
                    'Up to 1,000 messages',
                    '1 WhatsApp number',
                    'Flow builder',
                    'Contact management',
                    '14-day trial',
                ],
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
            ],
            [
                'name'           => 'Starter',
                'messages_limit' => 10000,
                'price'          => 999,
                'is_active'      => true,
                'features'       => [
                    'Up to 10,000 messages/month',
                    '1 WhatsApp number',
                    'Flow builder',
                    'Contact management',
                    'Basic analytics',
                    'Email support',
                ],
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
            [
                'name'           => 'Growth',
                'messages_limit' => 50000,
                'price'          => 2999,
                'is_active'      => true,
                'features'       => [
                    'Up to 50,000 messages/month',
                    '2 WhatsApp numbers',
                    'Flow builder + AI suggestions',
                    'CRM integration',
                    'Advanced analytics',
                    'Priority support',
                    'OTP API access',
                ],
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
            [
                'name'           => 'Enterprise',
                'messages_limit' => 200000,
                'price'          => 9999,
                'is_active'      => true,
                'features'       => [
                    'Up to 200,000 messages/month',
                    'Unlimited WhatsApp numbers',
                    'Full API access',
                    'Custom CRM integration',
                    'Dedicated account manager',
                    'SLA guarantee',
                    'Custom branding',
                ],
                'duration_type'         => 'monthly',
                'duration_months'       => 1,
                'max_users'             => null,  // null == unlimited
                'max_templates'         => null,
                'max_phone_numbers'     => 5,
                'max_campaigns'         => null,
                'max_contacts'          => null,
                'max_labels'            => null,
                'max_flow_nodes'        => null,
                'max_campaign_contacts' => null,
                'throttle_per_minute'   => 500,
            ],
        ];

        foreach ($plans as $plan) {
            Plan::updateOrCreate(['name' => $plan['name']], $plan);
        }

        $this->command->info('✅ Plans upserted: Trial, Starter, Growth, Enterprise');
    }
}
