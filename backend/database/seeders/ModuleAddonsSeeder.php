<?php

namespace Database\Seeders;

use App\Models\Addon;
use App\Support\Modules;
use Illuminate\Database\Seeder;

/**
 * One "unlock this feature" add-on per module, so any company can add HR, Finance, Instagram DM … on top of its plan.
 * Only fills in what is missing — prices the superadmin already edited (or add-ons they deleted) are left alone.
 *   php artisan db:seed --class=ModuleAddonsSeeder
 */
class ModuleAddonsSeeder extends Seeder
{
    /** module key => monthly ₹ (yearly = 10 × monthly, two months free) */
    private const PRICES = [
        'media_library' => 99, 'short_links' => 99, 'sticky_notes' => 49, 'todo' => 49, 'integrations' => 199,
        'wa_chat' => 499, 'wa_cloud' => 499, 'ai_agent' => 799,
        'crm' => 299, 'leads' => 299,
        'hr' => 399, 'finance' => 499,
        'social_dm' => 399, 'web_widgets' => 199, 'meta_ads' => 349,
    ];

    public function run(): void
    {
        $made = 0;
        foreach (Modules::REGISTRY as $key => $m) {
            $slug = "module-{$key}";
            if (Addon::withTrashed()->where('slug', $slug)->exists()) { continue; }
            $monthly = self::PRICES[$key] ?? 299;
            Addon::create([
                'name' => $m[0], 'slug' => $slug, 'description' => $m[3], 'type' => 'module',
                'price' => $monthly, 'price_yearly' => $monthly * 10, 'billing_cycle' => 'monthly',
                'config' => ['module' => $key], 'is_active' => true,
            ]);
            $made++;
        }
        $this->command?->info("✅ Feature add-ons created: {$made}");
    }
}
