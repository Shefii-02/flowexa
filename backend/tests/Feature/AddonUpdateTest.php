<?php

namespace Tests\Feature;

use App\Models\Addon;
use App\Models\CompanyAddon;
use App\Modules\PlanPurchase\Services\PlanPurchaseService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AddonUpdateTest extends TestCase
{
    private PlanPurchaseService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('addons', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('slug', 110)->unique(); $t->text('description')->nullable(); $t->string('type')->default('limit');
            $t->decimal('price', 10, 2)->default(0); $t->decimal('price_yearly', 10, 2)->nullable(); $t->string('billing_cycle')->default('monthly');
            $t->json('config')->nullable(); $t->boolean('is_active')->default(true); $t->timestamps(); $t->softDeletes();
        });
        Schema::create('company_addons', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('addon_id'); $t->unsignedBigInteger('payment_order_id')->nullable();
            $t->decimal('amount_paid', 10, 2)->default(0); $t->string('status')->default('active'); $t->string('billing_cycle')->nullable();
            $t->timestamp('starts_at')->nullable(); $t->timestamp('expires_at')->nullable(); $t->timestamps();
        });
        $this->svc = app(PlanPurchaseService::class);
    }

    private function addon(): Addon
    {
        return $this->svc->createAddon(['name' => 'Extra Users', 'type' => 'limit', 'price' => 99, 'billing_cycle' => 'monthly', 'config' => ['resource' => 'users', 'amount' => 5]]);
    }

    private function buy(Addon $a, int $company = 1, bool $expired = false): CompanyAddon
    {
        return CompanyAddon::create(['company_id' => $company, 'addon_id' => $a->id, 'status' => 'active', 'starts_at' => now()->subDay(), 'expires_at' => $expired ? now()->subHour() : now()->addMonth()]);
    }

    public function test_slug_is_made_from_the_name_and_made_unique(): void
    {
        $a = $this->addon();
        $b = $this->svc->createAddon(['name' => 'Extra Users', 'type' => 'limit', 'price' => 1, 'billing_cycle' => 'monthly']);
        $this->assertSame('extra-users', $a->slug);
        $this->assertSame('extra-users-2', $b->slug);
    }

    public function test_renaming_updates_the_slug_and_keeps_it_unique_but_not_against_itself(): void
    {
        $a = $this->addon();
        $same = $this->svc->updateAddon($a->id, ['name' => 'Extra Users', 'slug' => 'extra-users']);
        $this->assertSame('extra-users', $same->slug);                       // not "extra-users-2" — it does not clash with itself
        $renamed = $this->svc->updateAddon($a->id, ['name' => 'More Seats', 'slug' => 'more-seats']);
        $this->assertSame('more-seats', $renamed->slug);
    }

    public function test_apply_to_existing_yes_changes_what_current_buyers_get(): void
    {
        $a = $this->addon(); $held = $this->buy($a);
        $this->svc->updateAddon($a->id, ['config' => ['resource' => 'users', 'amount' => 20]], true);
        $this->assertSame($a->id, (int) $held->fresh()->addon_id);
        $this->assertSame(20, (int) $held->fresh()->addon->config['amount']);
    }

    public function test_apply_to_existing_no_keeps_buyers_on_the_old_terms(): void
    {
        $a = $this->addon(); $held = $this->buy($a); $lapsed = $this->buy($a, 2, expired: true);
        $this->svc->updateAddon($a->id, ['config' => ['resource' => 'users', 'amount' => 20]], false);

        $this->assertSame(20, (int) $a->fresh()->config['amount']);                   // new buyers get the new terms
        $this->assertNotSame($a->id, (int) $held->fresh()->addon_id);                 // current buyer moved to the hidden copy…
        $this->assertSame(5, (int) $held->fresh()->addon->config['amount']);         // …which keeps the old amount
        $this->assertTrue($held->fresh()->addon->trashed());
        $this->assertSame($a->id, (int) $lapsed->fresh()->addon_id);                  // an expired purchase is not moved
    }

    public function test_no_buyers_means_no_copy_is_made(): void
    {
        $a = $this->addon();
        $this->svc->updateAddon($a->id, ['config' => ['resource' => 'users', 'amount' => 9]], false);
        $this->assertSame(1, Addon::withTrashed()->count());
    }

    public function test_the_admin_list_hides_the_internal_copies_and_counts_active_buyers(): void
    {
        $a = $this->addon(); $this->buy($a); $this->buy($a, 2);
        $this->svc->updateAddon($a->id, ['price' => 120], false);   // creates a copy and moves the 2 buyers to it
        $rows = $this->svc->superAdminAddons();
        $this->assertCount(1, $rows);
        $this->assertSame(0, (int) $rows->first()->active_buyers);
    }
}
