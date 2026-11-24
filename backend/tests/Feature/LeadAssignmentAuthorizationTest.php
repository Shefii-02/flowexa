<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\LeadAssignment;
use App\Models\LeadAssignmentNotification;
use App\Models\StaffAvailability;
use App\Models\User;
use App\Modules\Lead\Http\Controllers\LeadAssignmentController;
use App\Services\LeadAssignment\LeadActivityLogger;
use App\Services\LeadAssignment\LeadAssignmentEngine;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** Who may accept / decline / complete / transfer a lead assignment. Controller called directly; engine and activity log are mocked. */
class LeadAssignmentAuthorizationTest extends TestCase
{
    private LeadAssignmentController $ctl;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('lead_assignments', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('contact_id')->nullable(); $t->unsignedBigInteger('lead_id')->nullable();
            $t->unsignedBigInteger('staff_id')->nullable(); $t->unsignedBigInteger('campaign_id')->nullable(); $t->unsignedBigInteger('ai_agent_session_id')->nullable();
            $t->string('source_type')->nullable(); $t->string('source_ref')->nullable(); $t->string('status')->default('pending'); $t->string('assignment_type')->nullable();
            $t->integer('priority')->default(5); $t->timestamp('accepted_at')->nullable(); $t->timestamp('first_reply_at')->nullable(); $t->integer('response_sla_minutes')->nullable();
            $t->boolean('sla_breached')->default(false); $t->timestamp('sla_breached_at')->nullable(); $t->timestamp('ai_takeover_at')->nullable(); $t->timestamp('ai_offered_at')->nullable();
            $t->timestamp('staff_confirmed_at')->nullable(); $t->string('transfer_reason')->nullable(); $t->unsignedBigInteger('transferred_from')->nullable();
            $t->text('notes')->nullable(); $t->json('candidate_staff_ids')->nullable(); $t->boolean('ignore_availability')->default(false); $t->timestamps();
            $t->string('assignment_method')->nullable(); $t->string('previous_status')->nullable(); $t->string('previous_lead_stage')->nullable();
            $t->string('routing_strategy')->nullable(); $t->unsignedBigInteger('routing_rule_id')->nullable();
        });
        Schema::create('users', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id')->nullable(); $t->string('name')->nullable(); $t->timestamps(); $t->softDeletes();
        });
        Schema::create('lead_assignment_rules', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->string('strategy')->nullable(); $t->timestamps();
        });
        Schema::create('lead_assignment_notifications', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id')->nullable(); $t->unsignedBigInteger('assignment_id'); $t->unsignedBigInteger('staff_id');
            $t->string('notification_type')->nullable(); $t->string('channel')->nullable(); $t->timestamp('sent_at')->nullable(); $t->timestamp('read_at')->nullable();
            $t->timestamp('responded_at')->nullable(); $t->string('response')->nullable(); $t->integer('response_delay_seconds')->nullable(); $t->timestamps();
        });
        Schema::create('staff_availability', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('staff_id')->unique(); $t->boolean('is_online')->default(false); $t->boolean('is_available')->default(true);
            $t->timestamp('last_seen_at')->nullable(); $t->unsignedInteger('current_leads_count')->default(0); $t->unsignedInteger('today_leads_count')->default(0);
            $t->unsignedInteger('today_conversions')->default(0); $t->unsignedInteger('total_conversions')->default(0); $t->float('avg_response_time_minutes')->nullable();
            $t->float('conversion_rate')->nullable(); $t->float('performance_score')->nullable(); $t->string('status')->default('offline'); $t->timestamps();
        });

        $logger = \Mockery::mock(LeadActivityLogger::class)->shouldIgnoreMissing();
        $engine = \Mockery::mock(LeadAssignmentEngine::class)->shouldIgnoreMissing();
        $this->ctl = new LeadAssignmentController($engine, $logger);
    }

    private function user(int $id, bool $manager = false): User
    {
        $u = new class extends User {
            public bool $mgr = false;
            public function isSuperAdmin(): bool { return false; }
            public function isOwner(): bool { return $this->mgr; }
            public function hasPermission(string $permission): bool { return false; }
        };
        $u->forceFill(['id' => $id, 'company_id' => 1]);
        $u->mgr = $manager;
        $u->setRelation('company', (new Company())->forceFill(['id' => 1]));
        return $u;
    }

    private function as(User $u): void { Auth::setUser($u); }

    private function assignment(array $x = []): LeadAssignment
    {
        return LeadAssignment::create($x + ['company_id' => 1, 'status' => 'assigned', 'staff_id' => 10]);
    }

    private function code(callable $fn): int
    {
        try { $r = $fn(); return $r->getStatusCode(); } catch (HttpException $e) { return $e->getStatusCode(); }
    }

    public function test_staff_cannot_accept_a_lead_that_was_not_offered_to_them(): void
    {
        $a = $this->assignment(['status' => 'notified', 'staff_id' => null]);
        $this->as($this->user(20));
        $this->assertSame(403, $this->code(fn () => $this->ctl->accept($a->id)));
        $this->assertNull($a->fresh()->staff_id);
    }

    public function test_offered_staff_can_accept_and_nobody_else_can_then_take_it(): void
    {
        $a = $this->assignment(['status' => 'notified', 'staff_id' => null]);
        LeadAssignmentNotification::create(['assignment_id' => $a->id, 'staff_id' => 20, 'company_id' => 1]);
        LeadAssignmentNotification::create(['assignment_id' => $a->id, 'staff_id' => 21, 'company_id' => 1]);

        $this->as($this->user(20));
        $this->assertSame(200, $this->code(fn () => $this->ctl->accept($a->id)));
        $this->assertSame(20, (int) $a->fresh()->staff_id);

        $this->as($this->user(21));   // also offered, but 20 already took it
        $this->assertSame(409, $this->code(fn () => $this->ctl->accept($a->id)));
        $this->assertSame(20, (int) $a->fresh()->staff_id);
    }

    public function test_decline_needs_an_open_offer(): void
    {
        $a = $this->assignment(['status' => 'notified', 'staff_id' => null]);
        $this->as($this->user(30));
        $this->assertSame(403, $this->code(fn () => $this->ctl->decline($a->id)));
    }

    public function test_only_the_assigned_staff_or_a_manager_can_complete(): void
    {
        $a = $this->assignment();                      // staff 10
        $this->as($this->user(20));
        $this->assertSame(403, $this->code(fn () => $this->ctl->complete($a->id)));
        $this->assertSame('assigned', $a->fresh()->status);

        $this->as($this->user(10));
        $this->assertSame(200, $this->code(fn () => $this->ctl->complete($a->id)));
        $this->assertSame('completed', $a->fresh()->status);
    }

    public function test_completing_twice_moves_the_counters_once(): void
    {
        $a = $this->assignment();
        StaffAvailability::create(['company_id' => 1, 'staff_id' => 10, 'status' => 'online', 'current_leads_count' => 3]);
        $this->as($this->user(10));
        $this->ctl->complete($a->id);
        $this->ctl->complete($a->id);
        $av = StaffAvailability::where('staff_id', 10)->first();
        $this->assertSame(2, (int) $av->current_leads_count);
        $this->assertSame(1, (int) $av->total_conversions);
    }

    public function test_restore_undoes_a_completion_and_the_counters(): void
    {
        $a = $this->assignment(['status' => 'accepted']);
        StaffAvailability::create(['company_id' => 1, 'staff_id' => 10, 'status' => 'online', 'current_leads_count' => 3]);
        $this->as($this->user(10));
        $this->ctl->complete($a->id);
        $this->assertSame('completed', $a->fresh()->status);
        $this->assertSame('accepted', $a->fresh()->previous_status);

        $this->assertSame(200, $this->code(fn () => $this->ctl->restore($a->id)));
        $this->assertSame('accepted', $a->fresh()->status);
        $av = StaffAvailability::where('staff_id', 10)->first();
        $this->assertSame(3, (int) $av->current_leads_count);
        $this->assertSame(0, (int) $av->total_conversions);
    }

    public function test_restore_is_refused_for_other_staff_and_when_nothing_to_undo(): void
    {
        $a = $this->assignment();
        $this->as($this->user(20));
        $this->assertSame(403, $this->code(fn () => $this->ctl->restore($a->id)));
        $this->as($this->user(10));
        $this->assertSame(422, $this->code(fn () => $this->ctl->restore($a->id)));
    }

    public function test_manager_can_complete_any_assignment(): void
    {
        $a = $this->assignment();
        $this->as($this->user(99, manager: true));
        $this->assertSame(200, $this->code(fn () => $this->ctl->complete($a->id)));
    }

    public function test_update_rejects_unknown_status_and_non_owners(): void
    {
        $a = $this->assignment();
        $this->as($this->user(20));
        $this->assertSame(403, $this->code(fn () => $this->ctl->update(Request::create('/', 'PUT', ['status' => 'completed']), $a->id)));

        $this->as($this->user(10));
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->ctl->update(Request::create('/', 'PUT', ['status' => 'banana']), $a->id);
    }
}
