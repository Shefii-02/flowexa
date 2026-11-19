<?php

namespace Tests\Feature;

use App\Models\Addon;
use App\Models\PaymentOrder;
use App\Models\Wallet;
use App\Modules\PlanPurchase\Services\PlanPurchaseService;
use App\Support\PlanLimits;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AddonPacksTest extends TestCase
{
    private PlanPurchaseService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        PlanLimits::forgetCache();
        Schema::create('addons', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('slug', 110)->unique(); $t->text('description')->nullable(); $t->string('type');
            $t->decimal('price', 10, 2)->default(0); $t->decimal('price_yearly', 10, 2)->nullable(); $t->string('billing_cycle')->default('monthly');
            $t->json('config')->nullable(); $t->boolean('is_active')->default(true); $t->timestamps(); $t->softDeletes();
        });
        Schema::create('company_addons', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('addon_id'); $t->unsignedBigInteger('payment_order_id')->nullable();
            $t->decimal('amount_paid', 10, 2)->default(0); $t->string('status')->default('active'); $t->string('billing_cycle')->nullable();
            $t->timestamp('starts_at')->nullable(); $t->timestamp('expires_at')->nullable(); $t->timestamps();
        });
        Schema::create('wallets', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id')->unique(); $t->bigInteger('balance')->default(0); $t->bigInteger('total_used')->default(0); $t->bigInteger('total_purchased')->default(0);
            $t->integer('free_quota_used')->default(0); $t->boolean('low_balance_alert')->default(false); $t->boolean('auto_recharge')->default(false);
            $t->integer('auto_recharge_amount')->nullable(); $t->integer('auto_recharge_threshold')->nullable(); $t->timestamp('free_quota_reset_at')->nullable(); $t->timestamps();
        });
        Schema::create('wallet_transactions', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('user_id')->nullable(); $t->string('type'); $t->bigInteger('amount');
            $t->bigInteger('balance_before'); $t->bigInteger('balance_after'); $t->string('description')->nullable(); $t->string('reference_id')->nullable(); $t->string('reference_type')->nullable(); $t->timestamps();
        });
        $this->svc = app(PlanPurchaseService::class);
    }

    private function activate(Addon $a, int $company = 1, string $cycle = 'one_time'): void
    {
        $order = (new PaymentOrder())->forceFill(['id' => 7, 'company_id' => $company]);
        $m = new \ReflectionMethod($this->svc, 'activateAddon'); $m->setAccessible(true);
        $m->invoke($this->svc, $order, $a, $cycle, 50.0);
    }

    public function test_storage_addon_adds_megabytes_to_the_media_allowance(): void
    {
        $a = $this->svc->createAddon(['name' => '+5 GB', 'type' => 'storage', 'price' => 99, 'billing_cycle' => 'monthly', 'config' => ['mb' => 5120]]);
        $this->activate($a, 1, 'monthly');
        PlanLimits::forgetCache();
        $this->assertSame(5120, PlanLimits::mediaBonusMb(1));
        $this->assertSame(0, PlanLimits::mediaBonusMb(2));      // another company gets nothing
    }

    public function test_raise_a_limit_on_media_storage_stacks_with_a_storage_addon(): void
    {
        $s = $this->svc->createAddon(['name' => 'Storage', 'type' => 'storage', 'price' => 1, 'billing_cycle' => 'one_time', 'config' => ['mb' => 1000]]);
        $l = $this->svc->createAddon(['name' => 'Raise', 'type' => 'limit', 'price' => 1, 'billing_cycle' => 'one_time', 'config' => ['resource' => 'media_storage_mb', 'amount' => 500]]);
        $this->activate($s); $this->activate($l);
        PlanLimits::forgetCache();
        $this->assertSame(1500, PlanLimits::mediaBonusMb(1));
    }

    public function test_message_pack_credits_the_wallet_on_purchase(): void
    {
        $a = $this->svc->createAddon(['name' => '10k messages', 'type' => 'message_pack', 'price' => 500, 'billing_cycle' => 'one_time', 'config' => ['messages' => 10000]]);
        $this->activate($a);
        $w = Wallet::where('company_id', 1)->first();
        $this->assertSame(10000, (int) $w->balance);
        $this->assertSame(10000, (int) $w->total_purchased);
        $this->assertSame(1, \App\Models\WalletTransaction::where('company_id', 1)->where('reference_type', 'addon')->count());
    }

    public function test_a_limit_addon_does_not_touch_the_wallet(): void
    {
        $a = $this->svc->createAddon(['name' => 'Users', 'type' => 'limit', 'price' => 1, 'billing_cycle' => 'monthly', 'config' => ['resource' => 'users', 'amount' => 3]]);
        $this->activate($a, 1, 'monthly');
        $this->assertNull(Wallet::where('company_id', 1)->first());
    }
}
