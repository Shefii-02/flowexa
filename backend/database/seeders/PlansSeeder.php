<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlansSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'name'            => 'Trial',
                'messages_limit'  => 1000,
                'price'           => 0,
                'is_active'       => true,
                'features'        => [
                    'Up to 1,000 messages',
                    '1 WhatsApp number',
                    'Flow builder',
                    'Contact management',
                    '14-day trial',
                ],
            ],
            [
                'name'            => 'Starter',
                'messages_limit'  => 10000,
                'price'           => 999,
                'is_active'       => true,
                'features'        => [
                    'Up to 10,000 messages/month',
                    '1 WhatsApp number',
                    'Flow builder',
                    'Contact management',
                    'Basic analytics',
                    'Email support',
                ],
            ],
            [
                'name'            => 'Growth',
                'messages_limit'  => 50000,
                'price'           => 2999,
                'is_active'       => true,
                'features'        => [
                    'Up to 50,000 messages/month',
                    '2 WhatsApp numbers',
                    'Flow builder + AI suggestions',
                    'CRM integration',
                    'Advanced analytics',
                    'Priority support',
                    'OTP API access',
                ],
            ],
            [
                'name'            => 'Enterprise',
                'messages_limit'  => 200000,
                'price'           => 9999,
                'is_active'       => true,
                'features'        => [
                    'Up to 200,000 messages/month',
                    'Unlimited WhatsApp numbers',
                    'Full API access',
                    'Custom CRM integration',
                    'Dedicated account manager',
                    'SLA guarantee',
                    'Custom branding',
                ],
            ],
        ];

        foreach ($plans as $plan) {
            Plan::firstOrCreate(['name' => $plan['name']], $plan);
        }

        $this->command->info('✅ Plans seeded: Trial, Starter, Growth, Enterprise');
    }
}
