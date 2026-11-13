<?php

namespace Tests\Unit;

use App\Support\PlanLimits;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlanLimitsAddonTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        PlanLimits::forgetCache();
        Schema::create('users', function ($t) { $t->id(); $t->unsignedBigInteger('company_id'); $t->timestamps(); $t->softDeletes(); });
        Schema::create('addons', function ($t) { $t->id(); $t->string('name'); $t->string('type'); $t->json('config')->nullable(); $t->boolean('is_active')->default(true); $t->timestamps(); $t->softDeletes(); });
        Schema::create('company_addons', function ($t) { $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('addon_id'); $t->string('status')->default('active'); $t->timestamp('expires_at')->nullable(); $t->timestamps(); });
        foreach (range(1, 3) as $i) DB::table('users')->insert(['company_id' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function plan(?int $maxUsers = 3): object { return (object) ['max_users' => $maxUsers]; }

    private function buy(string $resource, int $amount, array $addon = [], array $purchase = []): void
    {
        $id = DB::table('addons')->insertGetId($addon + ['name' => "+$amount $resource", 'type' => 'limit', 'config' => json_encode(['resource' => $resource, 'amount' => $amount]), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('company_addons')->insert($purchase + ['company_id' => 1, 'addon_id' => $id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        PlanLimits::forgetCache();
    }

    #[Test]
    public function at_the_limit_a_new_user_is_refused_until_an_addon_is_bought(): void
    {
        $this->assertNotNull(PlanLimits::violation(1, $this->plan(), 'users'));
        $this->buy('users', 5);
        $this->assertNull(PlanLimits::violation(1, $this->plan(), 'users'));
        $this->assertSame(8, PlanLimits::limit($this->plan(), 'users', 1));
        $this->assertSame(5, PlanLimits::remaining(1, $this->plan(), 'users'));
    }

    #[Test]
    public function purchases_stack_and_only_raise_their_own_resource(): void
    {
        $this->buy('users', 2); $this->buy('users', 3); $this->buy('contacts', 100);
        $this->assertSame(8, PlanLimits::limit($this->plan(), 'users', 1));
        $this->assertSame(3, PlanLimits::limit($this->plan(), 'users'), 'without a company only the plan applies');
    }

    #[Test]
    public function expired_cancelled_or_inactive_addon_purchases_give_nothing(): void
    {
        $this->buy('users', 5, [], ['expires_at' => now()->subDay()]);
        $this->buy('users', 5, [], ['status' => 'cancelled']);
        $this->buy('users', 5, ['type' => 'feature']);              // not a limit add-on
        $this->assertSame(3, PlanLimits::limit($this->plan(), 'users', 1));
    }

    #[Test]
    public function unlimited_stays_unlimited_and_usage_reports_the_extra(): void
    {
        $this->assertNull(PlanLimits::limit($this->plan(null), 'users', 1));
        $this->buy('users', 4);
        $u = PlanLimits::usage(1, $this->plan())['users'];
        $this->assertSame([7, 3, 4, false], [$u['limit'], $u['plan_limit'], $u['addon_extra'], $u['exceeded']]);
    }

    #[Test]
    public function the_violation_tells_the_ui_whether_an_addon_can_fix_it(): void
    {
        $this->assertFalse(PlanLimits::violation(1, $this->plan(), 'users')['addon_available']);
        DB::table('addons')->insert(['name' => '+5 staff', 'type' => 'limit', 'config' => json_encode(['resource' => 'users', 'amount' => 5]), 'created_at' => now(), 'updated_at' => now()]);
        $this->assertTrue(PlanLimits::violation(1, $this->plan(), 'users')['addon_available']);
    }
}
