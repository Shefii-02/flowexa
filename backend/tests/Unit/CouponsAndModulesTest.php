<?php

namespace Tests\Unit;

use App\Models\Addon;
use App\Models\Company;
use App\Models\Coupon;
use App\Models\PaymentOrder;
use App\Models\Plan;
use App\Modules\PlanPurchase\Services\PlanPurchaseService;
use App\Support\CouponService;
use App\Support\Entitlements;
use App\Support\Modules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Runs the real 2026_11_13 migration on top of minimal pre-existing tables, then exercises coupons, cart billing and module entitlements. */
class CouponsAndModulesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('plans', function ($t) { $t->id(); $t->string('name'); $t->decimal('price', 10, 2)->default(0); $t->decimal('price_yearly', 10, 2)->nullable(); $t->unsignedInteger('max_campaigns')->nullable(); $t->unsignedInteger('max_website_widgets')->nullable(); $t->boolean('is_active')->default(true); $t->json('features')->nullable(); $t->timestamps(); });
        Schema::create('companies', function ($t) { $t->id(); $t->string('name')->nullable(); $t->string('status')->default('active'); $t->unsignedBigInteger('plan_id')->nullable(); $t->timestamp('plan_expires_at')->nullable(); $t->timestamps(); $t->softDeletes(); });
        Schema::create('addons', function ($t) { $t->id(); $t->string('name'); $t->string('type')->default('limit'); $t->decimal('price', 10, 2)->default(0); $t->decimal('price_yearly', 10, 2)->nullable(); $t->string('billing_cycle')->default('monthly'); $t->json('config')->nullable(); $t->boolean('is_active')->default(true); $t->timestamps(); });
        Schema::create('company_addons', function ($t) { $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('addon_id'); $t->unsignedBigInteger('payment_order_id')->nullable(); $t->decimal('amount_paid', 10, 2)->default(0); $t->string('status')->default('active'); $t->string('billing_cycle')->default('monthly'); $t->timestamp('starts_at')->nullable(); $t->timestamp('expires_at')->nullable(); $t->timestamps(); });
        Schema::create('payment_orders', function ($t) { $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('user_id')->nullable(); $t->string('razorpay_order_id'); $t->string('razorpay_payment_id')->nullable(); $t->string('razorpay_signature')->nullable(); $t->decimal('amount', 10, 2); $t->integer('messages_credit')->default(0); $t->string('status')->default('pending'); $t->timestamps(); });
        Schema::create('company_plans', function ($t) { $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('plan_id'); $t->unsignedBigInteger('payment_order_id')->nullable(); $t->string('duration_type')->nullable(); $t->integer('duration_months')->nullable(); $t->decimal('amount_paid', 10, 2)->default(0); $t->string('status')->default('active'); $t->timestamp('starts_at')->nullable(); $t->timestamp('expires_at')->nullable(); $t->timestamp('cancelled_at')->nullable(); $t->text('notes')->nullable(); $t->timestamps(); });
        foreach (['max_surveys', 'max_short_links'] as $c) { /* added by the migration under test */ }
        (include database_path('migrations/2026_11_13_000001_plan_modules_coupons_and_revenue.php'))->up();
        Entitlements::forget();
    }

    private function plan(array $a = []): Plan { return Plan::create($a + ['name' => 'P' . uniqid(), 'price' => 1000, 'price_yearly' => 9000]); }
    private function company(?Plan $p = null): Company { return Company::create(['name' => 'Acme', 'plan_id' => $p?->id]); }
    private function coupon(array $a = []): Coupon { return Coupon::create($a + ['code' => 'SAVE10', 'name' => 'Ten off', 'discount_type' => 'percent', 'discount_value' => 10]); }

    #[Test]
    public function percent_coupon_respects_cap_and_flat_never_exceeds_the_price(): void
    {
        $this->coupon(['max_discount' => 50]); $this->coupon(['code' => 'FLAT', 'discount_type' => 'flat', 'discount_value' => 5000]);
        $svc = new CouponService();
        $this->assertSame(50.0, $svc->evaluate('save10', 1000, 'plan')['discount']);
        $r = $svc->evaluate('FLAT', 1000, 'plan');
        $this->assertSame([1000.0, 0.0], [$r['discount'], $r['final']]);
    }

    #[Test]
    public function coupon_rules_reject_expired_wrong_plan_and_reuse(): void
    {
        $p = $this->plan(); $other = $this->plan(); $c = $this->company($p);
        $this->coupon(['code' => 'ONLYP', 'plan_ids' => [$p->id], 'per_company_limit' => 1]);
        $svc = new CouponService();
        $this->assertSame(100.0, $svc->evaluate('ONLYP', 1000, 'plan', $c->id, $p->id)['discount']);
        $this->expectValidation(fn () => $svc->evaluate('ONLYP', 1000, 'plan', $c->id, $other->id), 'not valid for the selected plan');
        $this->coupon(['code' => 'OLD', 'expires_at' => now()->subDay()]);
        $this->expectValidation(fn () => $svc->evaluate('OLD', 1000, 'plan'), 'expired');

        $order = PaymentOrder::create(['company_id' => $c->id, 'razorpay_order_id' => 'o1', 'amount' => 900, 'status' => 'paid', 'subtotal' => 1000, 'discount' => 100, 'coupon_id' => Coupon::where('code', 'ONLYP')->value('id'), 'purpose' => 'plan']);
        $svc->redeem($order); $svc->redeem($order);   // second call is a no-op
        $this->assertSame(1, \App\Models\CouponRedemption::count());
        $this->expectValidation(fn () => $svc->evaluate('ONLYP', 1000, 'plan', $c->id, $p->id), 'already used');
    }

    private function expectValidation(callable $fn, string $contains): void
    {
        try { $fn(); $this->fail('expected a coupon error'); }
        catch (ValidationException $e) { $this->assertStringContainsString($contains, $e->errors()['coupon_code'][0]); }
    }

    #[Test]
    public function a_cart_with_a_full_discount_activates_without_a_gateway_and_module_addons_extend(): void
    {
        $c = $this->company($this->plan(['modules' => ['wa_chat']]));
        $hr = Addon::create(['name' => 'HR', 'type' => 'module', 'price' => 300, 'price_yearly' => 3000, 'config' => ['module' => 'hr']]);
        $fin = Addon::create(['name' => 'Finance', 'type' => 'module', 'price' => 200, 'config' => ['module' => 'finance']]);
        $this->coupon(['code' => 'FREE', 'discount_type' => 'percent', 'discount_value' => 100, 'per_company_limit' => 5]);

        $this->assertFalse(Entitlements::has($c->fresh(), 'hr'));
        $svc = new PlanPurchaseService();
        $r = $svc->createCartOrder($c->id, 1, [$hr->id, $fin->id], 'monthly', 'FREE');

        $this->assertTrue($r['free']);
        $this->assertTrue(Entitlements::has($c->fresh(), 'hr') && Entitlements::has($c->fresh(), 'finance'));
        $this->assertFalse(Entitlements::has($c->fresh(), 'meta_ads'));
        $o = PaymentOrder::first();
        $this->assertSame(['free', 'cart', 500.0, 500.0], [$o->gateway, $o->purpose, (float) $o->subtotal, (float) $o->discount]);
        $this->assertSame(1, \App\Models\CouponRedemption::count());

        // buying HR again extends the running add-on rather than stacking a second copy
        $svc->createCartOrder($c->id, 1, [$hr->id], 'monthly', 'FREE');
        $this->assertSame(1, \App\Models\CompanyAddon::where('addon_id', $hr->id)->count());
    }

    #[Test]
    public function plans_without_a_module_list_keep_everything_and_urls_map_to_modules(): void
    {
        $legacy = $this->company($this->plan());                                  // modules NULL → all on
        $this->assertTrue(Entitlements::has($legacy, 'hr'));
        $limited = $this->company($this->plan(['modules' => ['crm']]));
        $this->assertFalse(Entitlements::has($limited, 'hr'));
        $this->assertTrue(Entitlements::has($limited, 'crm'));
        $this->assertTrue(Entitlements::forCompany($this->company())['all'], 'no plan = unrestricted');

        $this->assertSame('hr', Modules::forPath('api/v1/hr/attendance'));
        $this->assertSame('finance', Modules::forPath('api/v1/accounts/invoices'));
        $this->assertNull(Modules::forPath('api/v1/plans/current'));
    }

    #[Test]
    public function a_deleted_plan_still_serves_the_companies_on_it(): void
    {
        $p = $this->plan(['modules' => ['hr']]); $c = $this->company($p);
        $p->delete();
        $this->assertNotNull($c->fresh()->plan);
        $this->assertTrue(Entitlements::has($c->fresh(), 'hr'));
        $this->assertSame(0, Plan::count());
    }
}
