<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\Plan;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;


// ════════════════════════════════════════════════════════════════════════════
// Topup Packages
// ════════════════════════════════════════════════════════════════════════════
class TopupPackagesSeeder extends Seeder
{
    public function run(): void
    {
        $packages = [
            ['messages' => 100,   'price' => 29,    'label' => '100 messages',    'is_popular' => false, 'sort_order' => 1],
            ['messages' => 250,   'price' => 59,    'label' => '250 messages',    'is_popular' => false, 'sort_order' => 2],
            ['messages' => 500,   'price' => 99,    'label' => '500 messages',    'is_popular' => false, 'sort_order' => 3],
            ['messages' => 1000,  'price' => 179,   'label' => '1,000 messages',  'is_popular' => false, 'sort_order' => 4],
            ['messages' => 2500,  'price' => 399,   'label' => '2,500 messages',  'is_popular' => false, 'sort_order' => 5],
            ['messages' => 5000,  'price' => 749,   'label' => '5,000 messages',  'is_popular' => true,  'sort_order' => 6],
            ['messages' => 7500,  'price' => 999,   'label' => '7,500 messages',  'is_popular' => false, 'sort_order' => 7],
            ['messages' => 10000, 'price' => 1199,  'label' => '10,000 messages', 'is_popular' => false, 'sort_order' => 8],
        ];

        foreach ($packages as $pkg) {
            DB::table('topup_packages')->updateOrInsert(
                ['messages' => $pkg['messages']],
                array_merge($pkg, ['is_active' => true, 'created_at' => now(), 'updated_at' => now()])
            );
        }

        $this->command->info('✅ Topup packages seeded: 100 to 10,000 messages');
    }
}
