<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\Plan;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

// ════════════════════════════════════════════════════════════════════════════
// Update Plans with limits
// ════════════════════════════════════════════════════════════════════════════
class UpdatePlansWithLimitsSeeder extends Seeder
{
    public function run(): void
    {
        $limits = [
            'Trial' => [
                'duration_type'          => 'custom',
                'duration_months'        => null,
                'max_users'              => 3,
                'max_templates'          => 5,
                'max_phone_numbers'      => 1,
                'max_campaigns'          => 5,
                'max_contacts'           => 500,
                'max_labels'             => 10,
                'max_flow_nodes'         => 20,
                'max_campaign_contacts'  => 500,
                'throttle_per_minute'    => 20,
            ],
            'Starter' => [
                'duration_type'          => 'monthly',
                'duration_months'        => 1,
                'max_users'              => 10,
                'max_templates'          => 20,
                'max_phone_numbers'      => 1,
                'max_campaigns'          => 20,
                'max_contacts'           => 5000,
                'max_labels'             => 25,
                'max_flow_nodes'         => 50,
                'max_campaign_contacts'  => 5000,
                'throttle_per_minute'    => 60,
            ],
            'Growth' => [
                'duration_type'          => 'monthly',
                'duration_months'        => 1,
                'max_users'              => 25,
                'max_templates'          => 50,
                'max_phone_numbers'      => 3,
                'max_campaigns'          => 50,
                'max_contacts'           => 25000,
                'max_labels'             => 100,
                'max_flow_nodes'         => 200,
                'max_campaign_contacts'  => 25000,
                'throttle_per_minute'    => 150,
            ],
            'Enterprise' => [
                'duration_type'          => 'monthly',
                'duration_months'        => 1,
                'max_users'              => null,  // unlimited
                'max_templates'          => null,
                'max_phone_numbers'      => 5,
                'max_campaigns'          => null,
                'max_contacts'           => null,
                'max_labels'             => null,
                'max_flow_nodes'         => null,
                'max_campaign_contacts'  => null,
                'throttle_per_minute'    => 500,
            ],
        ];

        foreach ($limits as $name => $data) {
            Plan::where('name', $name)->update($data);
        }

        $this->command->info('✅ Plans updated with duration and limits');
    }
}
