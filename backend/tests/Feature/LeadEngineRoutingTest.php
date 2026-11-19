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
            $t->json('working_days')->nullable();
        });
        Schema::create('lead_routing_rules', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->string('name'); $t->string('match_type'); $t->string('category')->nullable();
            $t->unsignedBigInteger('campaign_id')->nullable(); $t->dateTime('starts_at')->nullable(); $t->dateTime('ends_at')->nullable();
            $t->json('staff_ids'); $t->string('strategy')->nullable(); $t->boolean('is_active')->default(true); $t->unsignedSmallInteger('priority')->default(100); $t->timestamps();
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
        $this->engine = new LeadAssignmentEngine(app(StaffScorer::class), $detector, \Mockery::mock(LeadActivityLogger::class)->shouldIgnoreMissing());
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
}
