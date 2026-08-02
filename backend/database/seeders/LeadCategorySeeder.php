<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\LeadCategory;

class LeadCategorySeeder extends Seeder
{

    public static function seedForCompany(int $companyId): void
    {
        $defaults = [
            ['name' => 'General Enquiry',    'color' => '#6366f1', 'sort_order' => 0],
            ['name' => 'Product Demo',        'color' => '#1D9E75', 'sort_order' => 1],
            ['name' => 'Pricing Enquiry',     'color' => '#f59e0b', 'sort_order' => 2],
            ['name' => 'Support Request',     'color' => '#ef4444', 'sort_order' => 3],
            ['name' => 'Partnership',         'color' => '#8b5cf6', 'sort_order' => 4],
        ];

        foreach ($defaults as $cat) {
            LeadCategory::firstOrCreate(
                ['company_id' => $companyId, 'name' => $cat['name']],
                array_merge($cat, ['company_id' => $companyId])
            );
        }
    }
}


