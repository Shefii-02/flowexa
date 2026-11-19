<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\LeadRoutingRule;
use App\Models\User;
use App\Services\LeadAssignment\DuplicateLeadDetector;
use App\Services\LeadAssignment\LeadActivityLogger;
use App\Services\LeadAssignment\LeadAssignmentEngine;
use App\Services\LeadAssignment\StaffScorer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** LeadAssignmentEngine::assign end to end (hand-made tables): the manual "Auto assign" method override and category/campaign routing pools. */
class LeadEngineRoutingTest extends TestCase
{
    private LeadAssignmentEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        \App\Services\LeadAssignment\StaffPoolResolver::forgetMemo();
        Queue::fake();
        Schema::create('users', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->string('name')->default('x'); $t->boolean('is_active')->default(true);
            $t->unsignedSmallInteger('max_leads')->default(50); $t->timestamps(); $t->softDeletes();
        });
        Schema::create('staff_availability', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('staff_id')->unique();
            $t->boolean('is_online')->default(false); $t->boolean('is_available')->default(true); $t->timestamp('last_seen_at')->nullable();
            $t->unsignedInteger('current_leads_count')->default(0); $t->unsignedInteger('today_leads_count')->default(0);
            $t->unsignedInteger('today_conversions')->default(0); $t->unsignedInteger('total_conversions')->default(0);
            $t->float('avg_response_time_minutes')->nullable(); $t->float('conversion_rate')->nullable(); $t->float('performance_score')->nullable();
            $t->string('status')->default('online'); $t->timestamps();
        });
        Schema::create('contacts', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedInteger('total_leads_count')->default(0);
            $t->unsignedBigInteger('current_assignment_id')->nullable(); $t->timestamp('first_lead_at')->nullable(); $t->timestamp('last_lead_at')->nullable(); $t->timestamps();
        });
        Schema::create('wa_conversations', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('contact_id')->nullable(); $t->unsignedBigInteger('assigned_to')->nullable(); $t->timestamps();
        });
        Schema::create('lead_assignment_rules', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->boolean('auto_assign_enabled')->default(true); $t->string('strategy')->default('round_robin');
            $t->string('notification_mode')->default('auto'); $t->unsignedInteger('sla_minutes')->default(30); $t->timestamps();
            foreach (['weight_availability', 'weight_max_leads', 'weight_performance', 'weight_workload', 'ai_takeover_after_minutes', 'notification_gap_seconds',
                      'notification_timeout_seconds', 'max_notification_rounds', 'duplicate_window_days'] as $c) { $t->integer($c)->nullable(); }
            foreach (['duplicate_action', 'working_hours_start', 'working_hours_end', 'timezone'] as $c) { $t->string($c)->nullable(); }
            $t->json('working_days')->nullable(); $t->string('staff_scope')->default('all'); $t->json('staff_ids')->nullable();
        });
        Schema::create('lead_routing_rules', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->string('name'); $t->string('match_type'); $t->string('category')->nullable();
            $t->unsignedBigInteger('campaign_id')->nullable(); $t->dateTime('starts_at')->nullable(); $t->dateTime('ends_at')->nullable();
            $t->string('staff_scope')->default('selected'); $t->json('staff_ids')->nullable(); $t->string('strategy')->nullable(); $t->boolean('is_active')->default(true); $t->unsignedSmallInteger('priority')->default(100); $t->timestamps();
        });
        Schema::create('companies', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('plan_id')->nullable(); $t->softDeletes(); });
        \Illuminate\Support\Facades\DB::table('companies')->insert(['id' => 1]);
        Schema::create('hr_attendance', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('user_id'); $t->date('work_date');
            $t->timestamp('clock_in_at')->nullable(); $t->timestamp('clock_out_at')->nullable();
        });
        Schema::create('hr_break_sessions', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('attendance_id'); $t->timestamp('start_at')->nullable(); $t->timestamp('end_at')->nullable();
        });
        Schema::create('lead_assignments', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('contact_id')->nullable(); $t->unsignedBigInteger('lead_id')->nullable();
            $t->unsignedBigInteger('staff_id')->nullable(); $t->unsignedBigInteger('campaign_id')->nullable(); $t->unsignedBigInteger('ai_agent_session_id')->nullable();
            $t->string('source_type')->nullable(); $t->string('source_ref')->nullable(); $t->string('status')->default('pending'); $t->string('assignment_type')->nullable();
            $t->string('assignment_method')->nullable(); $t->string('previous_status')->nullable(); $t->string('previous_lead_stage')->nullable();
            $t->string('routing_strategy')->nullable(); $t->unsignedBigInteger('routing_rule_id')->nullable();
            $t->integer('priority')->default(5); $t->timestamp('accepted_at')->nullable(); $t->timestamp('first_reply_at')->nullable(); $t->integer('response_sla_minutes')->nullable();
            $t->boolean('sla_breached')->default(false); $t->timestamp('sla_breached_at')->nullable(); $t->timestamp('ai_takeover_at')->nullable(); $t->timestamp('ai_offered_at')->nullable();
            $t->timestamp('staff_confirmed_at')->nullable(); $t->string('transfer_reason')->nullable(); $t->unsignedBigInteger('transferred_from')->nullable();
            $t->text('notes')->nullable(); $t->json('candidate_staff_ids')->nullable(); $t->timestamps();
        });

        $detector = \Mockery::mock(DuplicateLeadDetector::class);
        $detector->shouldReceive('check')->andReturn(['is_duplicate' => false, 'recommended_action' => null, 'previous_staff' => null, 'previous_assignment' => null, 'reason' => null]);
        $this->engine = new LeadAssignmentEngine(app(StaffScorer::class), $detector, \Mockery::mock(LeadActivityLogger::class)->shouldIgnoreMissing(), app(\App\Services\LeadAssignment\StaffPoolResolver::class));
    }

    private function staff(string $name): User
    {
        $u = new User();
        $u->forceFill(['company_id' => 1, 'name' => $name, 'is_active' => true])->save();
        \App\Models\StaffAvailability::create(['company_id' => 1, 'staff_id' => $u->id, 'status' => 'online', 'is_available' => true]);
        return $u;
    }

    private function route(?string $category, ?int $campaignId = null): \App\Models\LeadAssignment
    {
        $company = (new Company())->forceFill(['id' => 1]);
        $contact = new Contact(); $contact->forceFill(['company_id' => 1])->save();
        $lead = (new Lead())->forceFill(['category' => $category, 'campaign_id' => $campaignId]);
        return $this->engine->assign($company, $contact, 'organic', $campaignId, null, 'manual', $lead, null, 'round_robin');
    }

    public function test_button_override_assigns_instantly_with_the_chosen_method(): void
    {
        $a = $this->staff('A'); $this->staff('B');
        $asg = $this->route(null);
        $this->assertSame('assigned', $asg->status);
        $this->assertNotNull($asg->staff_id);
        $this->assertSame('round_robin', $asg->assignment_method);
    }

    public function test_category_rule_limits_the_pool_and_round_robin_alternates(): void
    {
        $a = $this->staff('A'); $b = $this->staff('B'); $c = $this->staff('C');
        $rule = LeadRoutingRule::create(['company_id' => 1, 'name' => 'New course', 'match_type' => 'category', 'category' => 'New course', 'staff_ids' => [$b->id, $c->id]]);

        $picked = [];
        for ($i = 0; $i < 4; $i++) {
            $asg = $this->route('New course');
            $this->assertSame($rule->id, (int) $asg->routing_rule_id);
            $this->assertContains((int) $asg->staff_id, [$b->id, $c->id]);
            $picked[] = (int) $asg->staff_id;
        }
        $this->assertCount(2, array_unique($picked), 'both selected staff get leads');
        $this->assertNotContains($a->id, $picked);
    }

    public function test_non_matching_lead_ignores_the_rule(): void
    {
        $a = $this->staff('A'); $b = $this->staff('B');
        LeadRoutingRule::create(['company_id' => 1, 'name' => 'r', 'match_type' => 'category', 'category' => 'New course', 'staff_ids' => [$b->id]]);
        $first = $this->route('Other');
        $this->assertNull($first->routing_rule_id);
        $second = $this->route('Other');
        $this->assertNotSame($first->staff_id, $second->staff_id, 'normal round robin over everyone');
    }

    public function test_campaign_rule_and_unavailable_pool_goes_to_ai(): void
    {
        $a = $this->staff('A'); $b = $this->staff('B');
        LeadRoutingRule::create(['company_id' => 1, 'name' => 'camp', 'match_type' => 'campaign', 'campaign_id' => 5, 'staff_ids' => [$b->id]]);
        $asg = $this->route(null, 5);
        $this->assertSame($b->id, (int) $asg->staff_id);

        \App\Models\StaffAvailability::where('staff_id', $b->id)->update(['is_available' => false]);
        // no AI session table here — the engine falls to startAiAgent; assert it did not hand the lead to A
        try { $asg = $this->route(null, 5); } catch (\Throwable) { $asg = null; }
        $this->assertTrue($asg === null || $asg->staff_id === null, 'never leaks to staff outside the rule');
    }

    private function clockedIn(User ...$users): void
    {
        foreach ($users as $u) {
            \Illuminate\Support\Facades\DB::table('hr_attendance')->insert(['company_id' => 1, 'user_id' => $u->id, 'work_date' => now()->toDateString(), 'clock_in_at' => now()->subHour(), 'clock_out_at' => null]);
        }
    }

    private function companyPool(string $scope, array $ids = []): void
    {
        \App\Models\LeadAssignmentRule::where('company_id', 1)->exists()
            ?: \App\Models\LeadAssignmentRule::create(['company_id' => 1, 'strategy' => 'round_robin', 'notification_mode' => 'auto']);
        \App\Models\LeadAssignmentRule::where('company_id', 1)->update(['staff_scope' => $scope, 'staff_ids' => json_encode($ids)]);
    }

    private function staffOf(\App\Models\LeadAssignment $a): ?int { return $a->staff_id ? (int) $a->staff_id : null; }

    public function test_company_wide_selected_staff_only(): void
    {
        $a = $this->staff('A'); $b = $this->staff('B'); $c = $this->staff('C');
        $this->companyPool('selected', [$b->id, $c->id]);
        $got = [];
        for ($i = 0; $i < 4; $i++) { $got[] = $this->staffOf($this->route(null)); }
        $this->assertNotContains($a->id, $got);
        $this->assertCount(2, array_unique($got));
    }

    public function test_company_wide_online_only_uses_clocked_in_staff(): void
    {
        $a = $this->staff('A'); $b = $this->staff('B'); $c = $this->staff('C');
        $this->clockedIn($c);
        $this->companyPool('online');
        foreach (range(1, 3) as $_) { $this->assertSame($c->id, $this->staffOf($this->route(null))); }
    }

    public function test_online_only_with_nobody_clocked_in_assigns_no_staff(): void
    {
        $a = $this->staff('A'); $this->staff('B');
        $this->companyPool('online');
        try { $asg = $this->route(null); } catch (\Throwable) { $asg = null; }   // no ai_agent_sessions table in this test schema
        $this->assertTrue($asg === null || $asg->staff_id === null, 'never falls back to someone who is not clocked in');
    }

    public function test_selected_and_online_is_the_overlap(): void
    {
        $a = $this->staff('A'); $b = $this->staff('B'); $c = $this->staff('C');
        $this->clockedIn($a, $b);                      // A, B clocked in
        $this->companyPool('selected_online', [$b->id, $c->id]);   // pick B, C → only B qualifies
        foreach (range(1, 3) as $_) { $this->assertSame($b->id, $this->staffOf($this->route(null))); }
    }

    public function test_a_matching_routing_rule_overrides_the_company_pool(): void
    {
        $a = $this->staff('A'); $b = $this->staff('B'); $c = $this->staff('C');
        $this->companyPool('selected', [$a->id]);       // default pool: A only
        LeadRoutingRule::create(['company_id' => 1, 'name' => 'x', 'match_type' => 'category', 'category' => 'VIP', 'staff_scope' => 'selected', 'staff_ids' => [$b->id, $c->id]]);
        $vip = $this->staffOf($this->route('VIP'));
        $this->assertContains($vip, [$b->id, $c->id]);
        $this->assertSame($a->id, $this->staffOf($this->route('Other')), 'unmatched leads use the company pool');
    }

    public function test_routing_rule_with_scope_all_and_online(): void
    {
        $a = $this->staff('A'); $b = $this->staff('B');
        $this->companyPool('selected', [$a->id]);
        LeadRoutingRule::create(['company_id' => 1, 'name' => 'any', 'match_type' => 'category', 'category' => 'Open', 'staff_scope' => 'all', 'staff_ids' => []]);
        $got = [$this->staffOf($this->route('Open')), $this->staffOf($this->route('Open'))];
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $got, 'scope all ignores the company pool and spreads across everyone');

        LeadRoutingRule::query()->delete();
        $this->clockedIn($b);
        LeadRoutingRule::create(['company_id' => 1, 'name' => 'live', 'match_type' => 'category', 'category' => 'Live', 'staff_scope' => 'online', 'staff_ids' => []]);
        $this->assertSame($b->id, $this->staffOf($this->route('Live')));
    }

    public function test_attendance_scope_without_the_hr_section_falls_back_to_round_robin_over_everyone(): void
    {
        \Illuminate\Support\Facades\Schema::create('company_holidays', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('company_id'); $t->date('date'); $t->timestamps(); });
        \Illuminate\Support\Facades\Schema::create('company_working_hours', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedTinyInteger('weekday'); $t->boolean('is_open')->default(true); $t->string('start_time')->nullable(); $t->string('end_time')->nullable(); $t->timestamps();
        });
        $a = $this->staff('A'); $b = $this->staff('B');          // nobody is clocked in — but HR is not available, so that must not matter
        $this->companyPool('online');
        \App\Models\LeadAssignmentRule::where('company_id', 1)->update([
            'strategy' => 'algorithm', 'notification_mode' => 'auto', 'timezone' => 'UTC',
            'working_days' => json_encode([0, 1, 2, 3, 4, 5, 6]), 'working_hours_start' => '00:00:00', 'working_hours_end' => '23:59:59',
        ]);

        $noHr = new class extends \App\Services\LeadAssignment\StaffPoolResolver { public function attendanceAvailable(int $companyId): bool { return false; } };
        $detector = \Mockery::mock(DuplicateLeadDetector::class);
        $detector->shouldReceive('check')->andReturn(['is_duplicate' => false, 'recommended_action' => null, 'previous_staff' => null, 'previous_assignment' => null, 'reason' => null]);
        $engine = new LeadAssignmentEngine(app(StaffScorer::class), $detector, \Mockery::mock(LeadActivityLogger::class)->shouldIgnoreMissing(), $noHr);

        $got = [];
        foreach (range(1, 4) as $_) {
            $contact = new Contact(); $contact->forceFill(['company_id' => 1])->save();
            $asg = $engine->assign((new Company())->forceFill(['id' => 1]), $contact, 'organic', null, null, 'auto', (new Lead())->forceFill(['category' => null]));
            $got[] = (int) $asg->staff_id;
            $this->assertSame('round_robin', $asg->routing_strategy, 'the weighted method is replaced by round robin');
        }
        $this->assertEqualsCanonicalizing([$a->id, $a->id, $b->id, $b->id], $got, 'spread one by one across every available staff member');
    }
}
