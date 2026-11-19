<?php

namespace Tests\Feature;

use App\Services\LeadAssignment\StaffPoolResolver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** "Who may receive a lead": all / attendance-online / selected / selected-and-online. */
class StaffPoolResolverTest extends TestCase
{
    private StaffPoolResolver $r;

    protected function setUp(): void
    {
        parent::setUp();
        \App\Services\LeadAssignment\StaffPoolResolver::forgetMemo();
        Schema::create('companies', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('plan_id')->nullable(); $t->softDeletes(); });   // no plan = every module (HR available)
        \Illuminate\Support\Facades\DB::table('companies')->insert(['id' => 1]);
        Schema::create('hr_attendance', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('user_id'); $t->date('work_date');
            $t->timestamp('clock_in_at')->nullable(); $t->timestamp('clock_out_at')->nullable();
        });
        Schema::create('hr_break_sessions', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('attendance_id'); $t->timestamp('start_at')->nullable(); $t->timestamp('end_at')->nullable();
        });
        $this->r = new StaffPoolResolver();
    }

    private function clock(int $user, array $x = [], int $company = 1): int
    {
        return DB::table('hr_attendance')->insertGetId($x + ['company_id' => $company, 'user_id' => $user, 'work_date' => now()->toDateString(), 'clock_in_at' => now()->subHour(), 'clock_out_at' => null]);
    }

    public function test_all_means_no_restriction(): void
    {
        $this->assertNull($this->r->resolve(1, 'all', []));
        $this->assertNull($this->r->resolve(1, 'garbage', [5]), 'unknown scope behaves like all');
        $this->assertNull($this->r->resolve(1, null, null));
    }

    public function test_online_is_everyone_clocked_in_now_and_not_on_break(): void
    {
        $this->clock(10);                                                         // working
        $this->clock(11, ['clock_out_at' => now()->subMinutes(5)]);               // clocked out
        $onBreak = $this->clock(12); DB::table('hr_break_sessions')->insert(['attendance_id' => $onBreak, 'start_at' => now()->subMinutes(3)]);
        $afterBreak = $this->clock(13); DB::table('hr_break_sessions')->insert(['attendance_id' => $afterBreak, 'start_at' => now()->subHour(), 'end_at' => now()->subMinutes(40)]);
        $this->clock(14, ['work_date' => now()->subDay()->toDateString()]);       // yesterday's open row doesn't count
        $this->clock(15, [], 2);                                                  // another company
        $this->clock(16, ['clock_in_at' => null]);                                // row exists, never clocked in

        $this->assertEqualsCanonicalizing([10, 13], $this->r->resolve(1, 'online', []));
    }

    public function test_selected_is_exactly_the_chosen_list(): void
    {
        $this->assertSame([3, 4], $this->r->resolve(1, 'selected', [3, 4, 3]));
    }

    public function test_selected_online_is_the_overlap(): void
    {
        $this->clock(3); $this->clock(9);
        $this->assertSame([3], $this->r->resolve(1, 'selected_online', [3, 4]));
    }

    public function test_an_empty_result_is_never_an_empty_pool(): void
    {
        // [] would mean "no restriction" to the engine — an empty pool must be an impossible id instead
        $this->assertSame([StaffPoolResolver::NOBODY], $this->r->resolve(1, 'online', []));
        $this->assertSame([StaffPoolResolver::NOBODY], $this->r->resolve(1, 'selected', []));
        $this->assertSame([StaffPoolResolver::NOBODY], $this->r->resolve(1, 'selected_online', [3]));
    }

    public function test_without_the_hr_section_attendance_choices_fall_back_to_everyone(): void
    {
        $noHr = new class extends StaffPoolResolver { public function attendanceAvailable(int $companyId): bool { return false; } };

        $this->assertTrue($noHr->fallsBackToEveryone(1, 'online'));
        $this->assertTrue($noHr->fallsBackToEveryone(1, 'selected_online'));
        $this->assertFalse($noHr->fallsBackToEveryone(1, 'all'));
        $this->assertFalse($noHr->fallsBackToEveryone(1, 'selected'));
        $this->assertNull($noHr->resolve(1, 'online', []), 'online → all staff');
        $this->assertSame([3, 4], $noHr->resolve(1, 'selected_online', [3, 4]), 'selected + online → the selected staff');
        $this->assertSame([3, 4], $noHr->resolve(1, 'selected', [3, 4]), 'plain selected is unaffected');
    }

    public function test_hr_tables_missing_also_means_unavailable(): void
    {
        Schema::drop('hr_break_sessions'); Schema::drop('hr_attendance');
        $this->assertFalse($this->r->attendanceAvailable(1));
        $this->assertNull($this->r->resolve(1, 'online', []));
        $this->assertSame([], $this->r->onlineIds(1));
    }

    public function test_hr_available_when_the_plan_has_no_restriction_or_includes_hr(): void
    {
        $this->assertTrue($this->r->attendanceAvailable(1));
    }
}
