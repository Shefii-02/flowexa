<?php

namespace Tests\Unit;

use App\Models\Addon;
use App\Models\Plan;
use Database\Seeders\PlanPricingSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PlanPricingSeederTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        Schema::create('plans', function ($t) {
            $t->id(); $t->string('name'); $t->integer('messages_limit')->default(0); $t->decimal('price', 10, 2)->default(0); $t->decimal('price_yearly', 10, 2)->nullable();
            $t->text('features')->nullable(); $t->boolean('is_active')->default(true); $t->boolean('is_custom')->default(false); $t->unsignedBigInteger('custom_for_company_id')->nullable();
            $t->string('duration_type')->nullable(); $t->integer('duration_months')->nullable(); $t->integer('alert_before_days')->nullable();
            foreach (['max_users', 'max_templates', 'max_phone_numbers', 'max_wa_sessions', 'max_campaigns', 'max_contacts', 'max_labels', 'max_flow_nodes', 'max_campaign_contacts', 'throttle_per_minute',
                      'max_leads_per_month', 'max_lead_categories', 'max_roles', 'max_instagram_accounts', 'max_instagram_automations', 'max_instagram_dms_monthly', 'max_meta_ads_accounts', 'max_website_widgets'] as $c) { $t->integer($c)->nullable(); }
            foreach (['google_sheets_enabled', 'google_drive_enabled', 'calendar_enabled', 'email_integration_enabled'] as $c) { $t->boolean($c)->default(false); }
            $t->timestamps();
        });
        Schema::create('addons', function ($t) {
            $t->id(); $t->string('name'); $t->string('slug')->unique(); $t->text('description')->nullable(); $t->string('type'); $t->decimal('price', 10, 2);
            $t->decimal('price_yearly', 10, 2)->nullable(); $t->string('billing_cycle'); $t->text('config')->nullable(); $t->boolean('is_active')->default(true); $t->timestamps();
        });
    }

    private function seed_(): void { (new PlanPricingSeeder())->run(); }

    public function test_seeds_and_every_addon_targets_a_real_limit(): void
    {
        Plan::create(['name' => 'Starter', 'price' => 499]);
        Plan::create(['name' => 'Growth', 'price' => 1499, 'price_yearly' => 15000]);
        $this->seed_();

        $this->assertEquals(4990, Plan::where('name', 'Starter')->value('price_yearly'));
        $this->assertEquals(15000, Plan::where('name', 'Growth')->value('price_yearly'), 'a price the superadmin set is kept');
        $this->assertSame(0, (int) Plan::where('name', 'Testing')->value('price'));
        $this->assertGreaterThan(10, Addon::count());
        foreach (Addon::all() as $a) {
            $this->assertArrayHasKey($a->config['resource'], \App\Support\PlanLimits::REGISTRY);
            $this->assertEquals($a->price * 10, $a->price_yearly);
        }
    }

    public function test_rerun_does_not_duplicate_or_overwrite_edits(): void
    {
        $this->seed_();
        $n = Addon::count();
        Addon::where('slug', 'users-5')->update(['price' => 250]);
        Plan::where('name', 'Testing')->update(['max_users' => 9]);
        $this->seed_();
        $this->assertSame($n, Addon::count());
        $this->assertEquals(250, Addon::where('slug', 'users-5')->value('price'));
        $this->assertSame(9, (int) Plan::where('name', 'Testing')->value('max_users'));
        $this->assertSame(1, Plan::where('name', 'Testing')->count());
    }

    public function test_every_limit_belongs_to_one_group_and_plans_expose_sections(): void
    {
        $all = collect(\App\Support\PlanLimits::GROUPS)->flatMap(fn ($g) => $g[2]);
        $this->assertEqualsCanonicalizing(array_keys(\App\Support\PlanLimits::REGISTRY), $all->all());
        $this->assertSame($all->count(), $all->unique()->count());

        $plan = Plan::create(['name' => 'Starter', 'price' => 499, 'max_users' => 5]);
        $arr = $plan->fresh()->toArray();
        $this->assertEquals(round(499 * 12 * 0.8, 2), $arr['yearly_price']);
        $users = collect($arr['sections'])->firstWhere('key', 'team')['items'][0];
        $this->assertSame(['users', 5], [$users['key'], $users['value']]);

        $this->seed_();
        $this->assertSame('Staff & roles', Addon::where('slug', 'users-5')->first()->toArray()['group']['title']);
    }
}
