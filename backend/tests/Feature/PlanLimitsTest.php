<?php

namespace Tests\Feature;

use App\Support\PlanLimits;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PlanLimitsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        PlanLimits::forgetCache();
        Schema::create('users', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('company_id'); $t->timestamps(); $t->softDeletes(); });
        Schema::create('contacts', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('company_id'); $t->timestamps(); $t->softDeletes(); });
        Schema::create('roles', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('company_id'); $t->timestamps(); });
        Schema::create('leads', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('company_id'); $t->timestamps(); });
    }

    private function plan(array $x = []): object { return (object) ($x + ['max_users' => null, 'max_contacts' => null, 'max_roles' => null, 'max_leads_per_month' => null]); }
    private function add(string $table, int $company, int $n = 1, array $x = []): void
    {
        for ($i = 0; $i < $n; $i++) { DB::table($table)->insert($x + ['company_id' => $company, 'created_at' => now(), 'updated_at' => now()]); }
    }

    public function test_soft_deleted_rows_do_not_use_a_slot(): void
    {
        $this->add('users', 1, 3); $this->add('users', 1, 2, ['deleted_at' => now()]); $this->add('users', 2, 4);
        $this->assertSame(3, PlanLimits::used(1, 'users'));
        // deleting one frees the slot: at limit 3 the company is full, after a delete it is not
        $plan = $this->plan(['max_users' => 3]);
        $this->assertNotNull(PlanLimits::violation(1, $plan, 'users'));
        DB::table('users')->where('company_id', 1)->whereNull('deleted_at')->limit(1)->update(['deleted_at' => now()]);
        $this->assertNull(PlanLimits::violation(1, $plan, 'users'));
    }

    public function test_null_is_unlimited_and_zero_means_none(): void
    {
        $this->add('roles', 1, 50);
        $this->assertNull(PlanLimits::violation(1, $this->plan(), 'roles'));
        $this->assertNull(PlanLimits::violation(1, null, 'roles'), 'no plan → nothing is limited');
        $this->assertNotNull(PlanLimits::violation(9, $this->plan(['max_roles' => 0]), 'roles'));
    }

    public function test_one_less_than_the_limit_is_allowed_exactly_at_it_is_not(): void
    {
        $plan = $this->plan(['max_roles' => 3]);
        $this->add('roles', 1, 2);
        $this->assertNull(PlanLimits::violation(1, $plan, 'roles'));
        $this->add('roles', 1, 1);
        $v = PlanLimits::violation(1, $plan, 'roles');
        $this->assertSame('plan_limit_reached', $v['error_code']);
        $this->assertSame(3, $v['limit']); $this->assertSame(3, $v['current']); $this->assertSame(0, $v['remaining']);
        $this->assertStringContainsString('Limit exceeded', $v['message']);
        $this->assertStringContainsString('roles', $v['message']);
    }

    public function test_bulk_adds_report_how_many_fit(): void
    {
        $plan = $this->plan(['max_contacts' => 10]);
        $this->add('contacts', 1, 7);
        $this->assertNull(PlanLimits::violation(1, $plan, 'contacts', 3));
        $v = PlanLimits::violation(1, $plan, 'contacts', 5);
        $this->assertSame(3, $v['remaining']);
        $this->assertStringContainsString('can add 3 more, not 5', $v['message']);
        $this->assertSame(3, PlanLimits::remaining(1, $plan, 'contacts'));
    }

    public function test_monthly_limit_counts_only_this_month(): void
    {
        $plan = $this->plan(['max_leads_per_month' => 2]);
        $this->add('leads', 1, 5, ['created_at' => now()->subMonth()->startOfMonth()->addDay()]);   // last month: ignored
        $this->assertSame(0, PlanLimits::used(1, 'leads'));
        $this->add('leads', 1, 2);
        $this->assertSame(2, PlanLimits::used(1, 'leads'));
        $this->assertNotNull(PlanLimits::violation(1, $plan, 'leads'));
        $this->assertStringContainsString('per month', PlanLimits::violation(1, $plan, 'leads')['message']);
    }

    public function test_usage_listing_for_the_ui(): void
    {
        $this->add('contacts', 1, 8);
        $u = PlanLimits::usage(1, $this->plan(['max_contacts' => 10, 'max_users' => 0]));
        $this->assertSame(['used' => 8, 'limit' => 10, 'remaining' => 2, 'exceeded' => false, 'percent' => 80],
            array_intersect_key($u['contacts'], array_flip(['used', 'limit', 'remaining', 'exceeded', 'percent'])));
        $this->assertTrue($u['users']['exceeded'], 'a limit of 0 is exceeded straight away');
        $this->assertNull($u['roles']['limit']); $this->assertFalse($u['roles']['exceeded']); $this->assertNull($u['roles']['percent']);
        $this->assertArrayNotHasKey('templates', $u, 'resources whose table does not exist are skipped, not crashed on');
    }

    public function test_assert_can_add_throws_a_403_json_response(): void
    {
        $this->add('roles', 1, 2);
        try { PlanLimits::assertCanAdd(1, $this->plan(['max_roles' => 2]), 'roles'); $this->fail('expected a refusal'); }
        catch (HttpResponseException $e) {
            $this->assertSame(403, $e->getResponse()->getStatusCode());
            $this->assertSame('plan_limit_reached', $e->getResponse()->getData(true)['error_code']);
        }
        PlanLimits::assertCanAdd(1, $this->plan(['max_roles' => 3]), 'roles');   // fits → no exception
        $this->assertTrue(true);
    }

    public function test_super_admin_and_company_less_users_are_never_limited(): void
    {
        $admin = new class { public $company = null; public function isSuperAdmin() { return true; } };
        $this->assertNull(PlanLimits::context($admin));
        $this->assertNull(PlanLimits::context(null));
        $u = new class { public $company; public function isSuperAdmin() { return false; } };
        $u->company = (object) ['id' => 7, 'plan' => (object) ['max_roles' => 1]];
        $this->assertSame([7, $u->company->plan], PlanLimits::context($u));
    }
}
