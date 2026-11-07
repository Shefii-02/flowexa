<?php

namespace Tests\Unit;

use App\Models\Addon;
use App\Models\Company;
use App\Models\Plan;
use App\Modules\PlanPurchase\Services\PlanNoticeService;
use App\Modules\PlanPurchase\Services\PlanPurchaseService;
use App\Support\PlanLimits;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlanAddonBillingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'services.razorpay.secret' => 'rzp-secret']);
        DB::purge('sqlite');
        PlanLimits::forgetCache();
        Schema::create('plans', function ($t) { $t->id(); $t->string('name'); $t->decimal('price', 10, 2)->default(0); $t->decimal('price_yearly', 10, 2)->nullable(); $t->unsignedSmallInteger('alert_before_days')->default(7); $t->integer('max_users')->nullable(); $t->timestamps(); });
        Schema::create('companies', function ($t) { $t->id(); $t->string('name')->nullable(); $t->string('status')->default('active'); $t->unsignedBigInteger('plan_id')->nullable(); $t->timestamp('trial_ends_at')->nullable(); $t->timestamp('plan_expires_at')->nullable(); $t->timestamps(); });
        Schema::create('users', function ($t) { $t->id(); $t->unsignedBigInteger('company_id'); $t->timestamps(); $t->softDeletes(); });
        Schema::create('addons', function ($t) { $t->id(); $t->string('name'); $t->string('slug')->nullable(); $t->text('description')->nullable(); $t->string('type')->default('limit'); $t->decimal('price', 10, 2)->default(0); $t->decimal('price_yearly', 10, 2)->nullable(); $t->string('billing_cycle')->default('monthly'); $t->json('config')->nullable(); $t->boolean('is_active')->default(true); $t->timestamps(); });
        Schema::create('company_addons', function ($t) { $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('addon_id'); $t->unsignedBigInteger('payment_order_id')->nullable(); $t->decimal('amount_paid', 10, 2)->default(0); $t->string('status')->default('active'); $t->string('billing_cycle')->default('monthly'); $t->timestamp('starts_at')->nullable(); $t->timestamp('expires_at')->nullable(); $t->timestamps(); });
        Schema::create('payment_orders', function ($t) { $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('user_id')->nullable(); $t->string('razorpay_order_id'); $t->string('razorpay_payment_id')->nullable(); $t->string('razorpay_signature')->nullable(); $t->decimal('amount', 10, 2); $t->integer('messages_credit')->default(0); $t->string('status')->default('pending'); $t->timestamps(); });
    }

    private function svc(): PlanPurchaseService { return new PlanPurchaseService(); }

    private function addon(array $o = []): Addon
    {
        return Addon::create($o + ['name' => '+5 staff', 'type' => 'limit', 'price' => 300, 'price_yearly' => 2900, 'billing_cycle' => 'monthly',
            'config' => ['resource' => 'users', 'amount' => 5], 'is_active' => true]);
    }

    private function pay(int $companyId, string $orderId, float $amount, string $paymentId = 'pay_1'): array
    {
        DB::table('payment_orders')->insert(['company_id' => $companyId, 'razorpay_order_id' => $orderId, 'amount' => $amount, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        return ['razorpay_order_id' => $orderId, 'razorpay_payment_id' => $paymentId, 'razorpay_signature' => hash_hmac('sha256', "$orderId|$paymentId", 'rzp-secret')];
    }

    #[Test]
    public function plan_and_addon_prices_use_the_hand_set_yearly_price_with_a_fallback(): void
    {
        $plan = Plan::create(['name' => 'Growth', 'price' => 1000, 'price_yearly' => 9000]);
        $this->assertSame(9000.0, $this->svc()->calculatePrice($plan, 'yearly'));
        $this->assertSame(1000.0, $this->svc()->calculatePrice($plan, 'monthly'));
        $plan->price_yearly = null;
        $this->assertSame(9600.0, $this->svc()->calculatePrice($plan, 'yearly'));   // 12 × 1000 × 0.8

        $a = $this->addon();
        $this->assertSame([300.0, 2900.0], [$this->svc()->addonPrice($a, 'monthly'), $this->svc()->addonPrice($a, 'yearly')]);
        $a->price_yearly = null;
        $this->assertSame(3600.0, $this->svc()->addonPrice($a, 'yearly'));            // 12 × monthly
    }

    #[Test]
    public function a_one_time_addon_ignores_the_requested_cycle(): void
    {
        $a = $this->addon(['billing_cycle' => 'one_time', 'price' => 500]);
        $this->assertSame('one_time', $this->svc()->addonCycle($a, 'yearly'));
        $this->assertSame(500.0, $this->svc()->addonPrice($a, 'yearly'));
        $this->assertSame('yearly', $this->svc()->addonCycle($this->addon(), 'yearly'));
        $this->assertSame('monthly', $this->svc()->addonCycle($this->addon(), 'bogus'));
    }

    #[Test]
    public function paying_for_a_month_cannot_be_claimed_as_a_year_or_as_a_dearer_addon(): void
    {
        $a = $this->addon(); $dear = $this->addon(['name' => '+50 staff', 'price' => 3000, 'price_yearly' => 29000, 'config' => ['resource' => 'users', 'amount' => 50]]);

        $data = $this->pay(1, 'order_a', 300.0);
        $this->expectException(\Exception::class);
        $this->svc()->verifyAddonPayment(1, $data + ['addon_id' => $a->id, 'billing_cycle' => 'yearly']);   // paid monthly, asks for yearly
    }

    #[Test]
    public function a_cheap_order_cannot_be_claimed_as_a_dearer_addon(): void
    {
        $this->addon(); $dear = $this->addon(['name' => '+50 staff', 'price' => 3000, 'price_yearly' => 29000]);
        $data = $this->pay(1, 'order_b', 300.0);
        $this->expectException(\Exception::class);
        $this->svc()->verifyAddonPayment(1, $data + ['addon_id' => $dear->id, 'billing_cycle' => 'monthly']);
    }

    #[Test]
    public function a_correct_payment_activates_the_addon_with_the_right_expiry(): void
    {
        $a = $this->addon();
        $m = $this->svc()->verifyAddonPayment(1, $this->pay(1, 'order_m', 300.0, 'pay_m') + ['addon_id' => $a->id, 'billing_cycle' => 'monthly']);
        $y = $this->svc()->verifyAddonPayment(1, $this->pay(1, 'order_y', 2900.0, 'pay_y') + ['addon_id' => $a->id, 'billing_cycle' => 'yearly']);
        $this->assertSame('monthly', $m->billing_cycle);
        $this->assertEqualsWithDelta(now()->addMonth()->timestamp, $m->expires_at->timestamp, 5);
        $this->assertEqualsWithDelta(now()->addYear()->timestamp, $y->expires_at->timestamp, 5);
        PlanLimits::forgetCache();
        $this->assertSame(10, PlanLimits::bonus(1, 'users'), 'both purchases add capacity while active');
    }

    #[Test]
    public function when_an_addon_lapses_the_extra_data_is_kept_but_nothing_new_can_be_added_and_notices_say_so(): void
    {
        $plan = Plan::create(['name' => 'Starter', 'price' => 999, 'max_users' => 3]);
        $company = Company::forceCreate(['name' => 'Acme', 'status' => 'active', 'plan_id' => $plan->id, 'plan_expires_at' => now()->addDays(40)]);
        foreach (range(1, 7) as $i) DB::table('users')->insert(['company_id' => $company->id, 'created_at' => now(), 'updated_at' => now()]);   // 7 staff on a 3-staff plan: 5-pack was bought
        $a = $this->addon();
        DB::table('company_addons')->insert(['company_id' => $company->id, 'addon_id' => $a->id, 'status' => 'active', 'billing_cycle' => 'monthly', 'starts_at' => now()->subMonth(), 'expires_at' => now()->addDays(5), 'created_at' => now(), 'updated_at' => now()]);
        PlanLimits::forgetCache();

        $types = fn () => array_column((new PlanNoticeService)->forCompany($company->fresh()), 'type');
        $this->assertContains('addon_expiring', $types());                       // 5 days left: warned
        $this->assertNull(PlanLimits::violation($company->id, $plan, 'users', 1), '7 of 8: one more still fits while the pack is active');
        $this->assertNotNull(PlanLimits::violation($company->id, $plan, 'users', 2), 'two more would not');

        DB::table('company_addons')->update(['expires_at' => now()->subDay()]);   // it lapses
        PlanLimits::forgetCache();
        $this->assertSame(7, DB::table('users')->where('company_id', $company->id)->count(), 'nothing is deleted');
        $this->assertNotNull(PlanLimits::violation($company->id, $plan, 'users', 1), 'but no new staff can be added');
        $this->assertSame(4, PlanLimits::usage($company->id, $plan)['users']['over_by']);
        $t = $types();
        $this->assertContains('addon_expired', $t);
        $this->assertContains('over_limit', $t);
    }

    #[Test]
    public function the_plan_ending_notice_uses_the_plans_own_alert_window(): void
    {
        $plan = Plan::create(['name' => 'Starter', 'price' => 999, 'alert_before_days' => 10]);
        $c = Company::forceCreate(['name' => 'A', 'status' => 'active', 'plan_id' => $plan->id, 'plan_expires_at' => now()->addDays(9)]);
        $this->assertContains('plan_expiring', array_column((new PlanNoticeService)->forCompany($c->fresh()), 'type'));
        DB::table('companies')->where('id', $c->id)->update(['plan_expires_at' => now()->addDays(11)]);
        $this->assertNotContains('plan_expiring', array_column((new PlanNoticeService)->forCompany($c->fresh()), 'type'));
        DB::table('companies')->where('id', $c->id)->update(['plan_expires_at' => now()->subDay()]);
        $this->assertContains('plan_expired', array_column((new PlanNoticeService)->forCompany($c->fresh()), 'type'));
    }
}
