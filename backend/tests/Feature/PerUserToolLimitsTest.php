<?php

namespace Tests\Feature;

use App\Support\PlanLimits;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PerUserToolLimitsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        PlanLimits::forgetCache();
        Schema::create('sticky_notes', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('user_id'); $t->timestamps(); });
        Schema::create('crm_tasks', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('assigned_to')->nullable(); $t->string('type')->default('todo'); $t->string('status')->default('open'); $t->timestamps(); $t->softDeletes();
        });
    }

    private function plan(?int $notes, ?int $todos): object { return (object) ['max_sticky_notes_per_user' => $notes, 'max_todos_per_user' => $todos]; }
    private function note(int $user, int $company = 1): void { DB::table('sticky_notes')->insert(['company_id' => $company, 'user_id' => $user, 'created_at' => now(), 'updated_at' => now()]); }
    private function todo(int $user, string $status = 'open', string $type = 'todo', array $x = []): void
    {
        DB::table('crm_tasks')->insert($x + ['company_id' => 1, 'assigned_to' => $user, 'type' => $type, 'status' => $status, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_sticky_notes_are_counted_per_user(): void
    {
        $this->note(5); $this->note(5); $this->note(6); $this->note(5, 2);
        $this->assertSame(2, PlanLimits::usedByUser(1, 5, 'sticky_notes_per_user'));
        $plan = $this->plan(2, null);
        $this->assertNotNull(PlanLimits::userViolation(1, 5, $plan, 'sticky_notes_per_user'));   // user 5 is full
        $this->assertNull(PlanLimits::userViolation(1, 6, $plan, 'sticky_notes_per_user'));      // user 6 still has room
    }

    public function test_completed_todos_do_not_count(): void
    {
        $this->todo(5); $this->todo(5); $this->todo(5, 'done'); $this->todo(5, 'done'); $this->todo(5, 'cancelled');
        $this->todo(5, 'open', 'followup');                                  // a follow-up is not a to-do item
        $this->todo(5, 'open', 'todo', ['deleted_at' => now()]);             // deleted
        $this->assertSame(2, PlanLimits::usedByUser(1, 5, 'todos_per_user'));
        $this->assertNull(PlanLimits::userViolation(1, 5, $this->plan(null, 3), 'todos_per_user'));   // 2 open of 3 — one more fits
        $this->assertNotNull(PlanLimits::userViolation(1, 5, $this->plan(null, 2), 'todos_per_user'));
    }

    public function test_finishing_a_todo_frees_a_slot(): void
    {
        $this->todo(5); $this->todo(5);
        $plan = $this->plan(null, 2);
        $this->assertNotNull(PlanLimits::userViolation(1, 5, $plan, 'todos_per_user'));
        DB::table('crm_tasks')->where('assigned_to', 5)->limit(1)->update(['status' => 'done']);
        $this->assertNull(PlanLimits::userViolation(1, 5, $plan, 'todos_per_user'));
    }

    public function test_blank_limit_is_unlimited_and_zero_means_none(): void
    {
        for ($i = 0; $i < 50; $i++) { $this->note(5); }
        $this->assertNull(PlanLimits::userViolation(1, 5, $this->plan(null, null), 'sticky_notes_per_user'));
        $this->assertNotNull(PlanLimits::userViolation(1, 9, $this->plan(0, null), 'sticky_notes_per_user'));
    }

    public function test_the_error_payload_matches_the_other_plan_limits(): void
    {
        $this->todo(5);
        $v = PlanLimits::userViolation(1, 5, $this->plan(null, 1), 'todos_per_user');
        $this->assertSame('plan_limit_reached', $v['error_code']);
        $this->assertSame(1, $v['limit']); $this->assertSame(1, $v['current']);
        $this->assertStringContainsString('Completed items are not counted', $v['message']);
    }

    public function test_assert_throws_a_403_response(): void
    {
        $this->note(5);
        $user = new class { public $id = 5; public $company_id = 1; public $company; public function isSuperAdmin() { return false; } };
        $user->company = (object) ['id' => 1, 'plan' => $this->plan(1, null)];
        try { PlanLimits::assertUserCanAdd($user, 'sticky_notes_per_user'); $this->fail('should have thrown'); }
        catch (HttpResponseException $e) { $this->assertSame(403, $e->getResponse()->getStatusCode()); }
    }

    public function test_plan_sections_list_the_per_user_limits_under_common(): void
    {
        $plan = new \App\Models\Plan(); $plan->forceFill(['max_sticky_notes_per_user' => 5, 'max_todos_per_user' => 20]);
        $common = collect(PlanLimits::sectionsFor($plan))->firstWhere('key', 'common');
        $this->assertSame(5, collect($common['items'])->firstWhere('key', 'sticky_notes_per_user')['value']);
        $this->assertSame(20, collect($common['items'])->firstWhere('key', 'todos_per_user')['value']);
    }
}
