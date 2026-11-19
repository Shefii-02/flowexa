<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\StaffAvailability;
use App\Models\User;
use App\Services\LeadAssignment\LeadAssignmentEngine;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LeadAssignmentWorkloadTest extends TestCase
{
    private LeadAssignmentEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('users', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->string('name')->default('x'); $t->boolean('is_active')->default(true);
            $t->unsignedSmallInteger('max_leads')->default(50); $t->unsignedBigInteger('role_id')->nullable(); $t->timestamps(); $t->softDeletes();
        });
        Schema::create('roles', function (Blueprint $t) { $t->id(); $t->string('name')->nullable(); $t->json('permissions')->nullable(); $t->timestamps(); });
        \Illuminate\Support\Facades\DB::table('roles')->insert(['id' => 1, 'name' => 'sales', 'permissions' => json_encode(['leads.manage'])]);
        Schema::create('staff_availability', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('staff_id')->unique();
            $t->boolean('is_online')->default(false); $t->boolean('is_available')->default(true); $t->timestamp('last_seen_at')->nullable();
            $t->unsignedInteger('current_leads_count')->default(0); $t->unsignedInteger('today_leads_count')->default(0);
            $t->unsignedInteger('today_conversions')->default(0); $t->unsignedInteger('total_conversions')->default(0);
            $t->float('avg_response_time_minutes')->nullable(); $t->float('conversion_rate')->nullable(); $t->float('performance_score')->nullable();
            $t->string('status')->default('offline'); $t->timestamps();
        });
        $this->engine = app(LeadAssignmentEngine::class);
    }

    private function staff(int $max = 50): User
    {
        $u = new User();
        $u->forceFill(['company_id' => 1, 'name' => 'S', 'is_active' => true, 'max_leads' => $max, 'role_id' => 1])->save();
        return $u;
    }

    public function test_bump_workload_counts_the_lead_and_marks_busy_at_capacity(): void
    {
        $s = $this->staff(2);
        $this->engine->bumpWorkload(1, $s);
        $a = StaffAvailability::where('staff_id', $s->id)->first();
        $this->assertSame(1, (int) $a->current_leads_count);
        $this->assertSame('online', $a->status);   // a new availability row starts available (offline only when HR attendance drives availability)

        $this->engine->bumpWorkload(1, $s);
        $a = $a->fresh();
        $this->assertSame(2, (int) $a->current_leads_count);
        $this->assertSame('busy', $a->status);
        $this->assertFalse((bool) $a->is_available);
    }

    public function test_round_robin_spreads_leads_across_available_staff_once_workload_is_counted(): void
    {
        $company = (new Company())->forceFill(['id' => 1]);
        $a = $this->staff(); $b = $this->staff();
        foreach ([$a, $b] as $s) { StaffAvailability::ensureExists(1, $s->id)->update(['status' => 'online', 'is_available' => true]); }

        $picked = [];
        for ($i = 0; $i < 6; $i++) {
            $s = $this->engine->roundRobinPick($company);
            $this->assertNotNull($s);
            $this->engine->bumpWorkload(1, $s);
            $picked[] = $s->id;
        }
        $counts = array_count_values($picked);
        $this->assertSame(3, $counts[$a->id]);
        $this->assertSame(3, $counts[$b->id]);
    }

    public function test_busy_or_unavailable_staff_are_skipped(): void
    {
        $company = (new Company())->forceFill(['id' => 1]);
        $busy = $this->staff(); $free = $this->staff();
        StaffAvailability::ensureExists(1, $busy->id)->update(['status' => 'busy', 'is_available' => false]);
        StaffAvailability::ensureExists(1, $free->id)->update(['status' => 'online', 'is_available' => true]);
        $this->assertSame($free->id, $this->engine->roundRobinPick($company)->id);
    }
}
