<?php

namespace Database\Seeders;

use App\Models\IndustryTemplate;
use Illuminate\Database\Seeder;

/**
 * One-time migration of the industry templates that used to live in
 * config/industry_templates.php into the industry_templates table, so SuperAdmin can manage
 * them. Idempotent — matches on `key`, so re-running updates rather than duplicates.
 */
class IndustryTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $legacy = config('industry_templates', []);
        $order  = 1;

        foreach ($legacy as $key => $tpl) {
            IndustryTemplate::updateOrCreate(
                ['key' => $key],
                [
                    'name'                  => $tpl['name'] ?? ucfirst($key),
                    'listing_type'          => $tpl['listing_type'] ?? 'product',
                    'attribute_schema'      => $tpl['attribute_schema'] ?? [],
                    'qualification_fields'  => $tpl['qualification_fields'] ?? [],
                    'question_flow'         => $tpl['question_flow'] ?? [],
                    'agent_prompt'          => $tpl['agent_prompt'] ?? '',
                    'lead_source'           => $tpl['lead_source'] ?? 'website_widget',
                    'is_active'             => true,
                    'sort_order'            => $order++,
                ]
            );
        }

        $this->command?->info('  └─ Industry templates migrated: ' . count($legacy));
    }
}
