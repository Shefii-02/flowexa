<?php

namespace Tests\Feature;

use App\Models\StaffAvailability;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** The "open leads" counter must follow the leads people really hold — recalled, closed and deleted leads free their slots. */
class RecountWorkloadTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('users', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('company_id')->nullable(); $t->integer('max_leads')->default(50); $t->timestamps(); $t->softDeletes(); });
        Schema::create('leads', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('assigned_to')->nullable(); $t->string('stage')->default('new'); $t->softDeletes(); });
        Schema::create('staff_availability', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('staff_id')->unique(); $t->boolean('is_online')->default(false); $t->boolean('is_available')->default(true);
            $t->timestamp('last_seen_at')->nullable(); $t->unsignedInteger('current_leads_count')->default(0); $t->unsignedInteger('today_leads_count')->default(0);
            $t->unsignedInteger('today_conversions')->default(0); $t->unsignedInteger('total_conversions')->default(0); $t->float('avg_response_time_minutes')->nullable();
            $t->float('conversion_rate')->nullable(); $t->float('performance_score')->nullable(); $t->string('status')->default('online'); $t->timestamps();
        });
    }

    public function test_a_stale_count_is_corrected_and_a_wrongly_busy_person_is_freed(): void
    {
        DB::table('users')->insert([['id' => 1, 'company_id' => 1, 'max_leads' => 100], ['id' => 2, 'company_id' => 1, 'max_leads' => 100]]);
        // person 1 shows 101 / 100 and "busy" but holds nothing; person 2 shows 0 but really holds 2 open leads (one more is closed, one deleted)
        StaffAvailability::create(['company_id' => 1, 'staff_id' => 1, 'current_leads_count' => 101, 'status' => 'busy', 'is_available' => false]);
        StaffAvailability::create(['company_id' => 1, 'staff_id' => 2, 'current_leads_count' => 0]);
        foreach ([['new', null], ['follow_up', null], ['enrolled', null], ['new', now()]] as [$stage, $deleted]) {
            DB::table('leads')->insert(['company_id' => 1, 'assigned_to' => 2, 'stage' => $stage, 'deleted_at' => $deleted]);
        }

        StaffAvailability::recountWorkload(1);

        $a = StaffAvailability::where('staff_id', 1)->first();
        $b = StaffAvailability::where('staff_id', 2)->first();
        $this->assertSame(0, (int) $a->current_leads_count);
        $this->assertSame('online', $a->status);
        $this->assertTrue((bool) $a->is_available);
        $this->assertSame(2, (int) $b->current_leads_count);
    }

    public function test_someone_really_at_their_limit_stays_busy(): void
    {
        DB::table('users')->insert([['id' => 1, 'company_id' => 1, 'max_leads' => 2]]);
        StaffAvailability::create(['company_id' => 1, 'staff_id' => 1, 'current_leads_count' => 0, 'status' => 'busy', 'is_available' => false]);
        DB::table('leads')->insert([['company_id' => 1, 'assigned_to' => 1, 'stage' => 'new'], ['company_id' => 1, 'assigned_to' => 1, 'stage' => 'new']]);

        StaffAvailability::recountWorkload(1);

        $a = StaffAvailability::where('staff_id', 1)->first();
        $this->assertSame(2, (int) $a->current_leads_count);
        $this->assertSame('busy', $a->status);
    }
}
