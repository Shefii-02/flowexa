<?php

namespace Database\Seeders;

use App\Models\Addon;
use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * Yearly prices, the Testing plan and the "raise one limit" add-ons.
 *
 * Safe to re-run: it only FILLS IN what is missing. A price the superadmin has already edited is never overwritten
 * (use the Plans / Add-ons screens for that). Run after PlansSeeder:
 *   php artisan db:seed --class=PlanPricingSeeder
 */
class PlanPricingSeeder extends Seeder
{
    /** resource key (see PlanLimits::REGISTRY) => [pack size, monthly ₹] — one pack per resource; add more rows for bigger packs. */
    private const PACKS = [
        'users'              => [[5, 199], [20, 699]],
        'roles'              => [[5, 99]],
        'templates'          => [[20, 149]],
        'phone_numbers'      => [[1, 299]],
        'wa_sessions'        => [[1, 249]],
        'campaigns'          => [[25, 199]],
        'contacts'           => [[5000, 199], [25000, 699]],
        'labels'             => [[50, 99]],
        'flow_nodes'         => [[100, 199]],
        'lead_categories'    => [[10, 99]],
        'instagram_accounts' => [[1, 249]],
        'meta_ads_accounts'  => [[1, 249]],
        'website_widgets'    => [[1, 149]],
        'leads'              => [[1000, 299], [5000, 999]],
    ];

    public function run(): void
    {
        // Yearly = 10 × monthly (two months free) unless the superadmin already set one.
        $filled = 0;
        Plan::whereNull('price_yearly')->where('price', '>', 0)->get()->each(function (Plan $p) use (&$filled) {
            $p->update(['price_yearly' => round((float) $p->price * 10)]); $filled++;
        });

        // Testing plan: a fixed-length trial-style plan; when it ends the company has to buy a real plan.
        if (!Plan::withTrashed()->where('name', 'Testing')->exists()) Plan::create(['name' => 'Testing'] + [
            'messages_limit' => 500, 'price' => 0, 'is_active' => true, 'is_custom' => false,
            'features' => ['Try the platform for a fixed period', 'Buy any plan when it ends'],
            'duration_type' => 'custom', 'duration_months' => 1, 'alert_before_days' => 3,
            'max_users' => 2, 'max_templates' => 5, 'max_phone_numbers' => 1, 'max_wa_sessions' => 1, 'max_campaigns' => 3,
            'max_contacts' => 200, 'max_labels' => 5, 'max_flow_nodes' => 15, 'max_campaign_contacts' => 200, 'throttle_per_minute' => 20,
            'max_leads_per_month' => 50, 'max_lead_categories' => 3, 'max_roles' => 2,
            'max_instagram_accounts' => 1, 'max_meta_ads_accounts' => 1, 'max_website_widgets' => 1,
            'google_sheets_enabled' => false, 'google_drive_enabled' => false, 'calendar_enabled' => true, 'email_integration_enabled' => false,
            'modules' => ['media_library', 'sticky_notes', 'todo', 'wa_chat', 'wa_cloud', 'crm', 'leads'],
        ]);

        $created = 0;
        foreach (self::PACKS as $resource => $packs) {
            $label = \App\Support\PlanLimits::label($resource);
            foreach ($packs as [$amount, $monthly]) {
                $addon = Addon::firstOrCreate(['slug' => "{$resource}-{$amount}"], [
                    'name'          => "+" . number_format($amount) . " {$label}",
                    'description'   => "Adds " . number_format($amount) . " to your {$label} limit. Your plan is unchanged.",
                    'type'          => 'limit',
                    'price'         => $monthly,
                    'price_yearly'  => $monthly * 10,
                    'billing_cycle' => 'monthly',
                    'config'        => ['resource' => $resource, 'amount' => $amount],
                    'is_active'     => true,
                ]);
                $created += (int) $addon->wasRecentlyCreated;
            }
        }

        $this->command?->info("✅ Yearly price set on {$filled} plan(s) · Testing plan ensured · {$created} new add-on(s)");
    }
}
