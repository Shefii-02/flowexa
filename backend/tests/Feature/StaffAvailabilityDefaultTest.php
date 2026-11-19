<?php

namespace Tests\Feature;

use App\Models\StaffAvailability;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** A new availability row must start AVAILABLE — except for a company that really runs availability from HR attendance. */
class StaffAvailabilityDefaultTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('staff_availability', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('staff_id')->unique();
            $t->boolean('is_online')->default(false); $t->boolean('is_available')->default(true); $t->timestamp('last_seen_at')->nullable();
            $t->unsignedInteger('current_leads_count')->default(0); $t->unsignedInteger('today_leads_count')->default(0);
            $t->unsignedInteger('today_conversions')->default(0); $t->unsignedInteger('total_conversions')->default(0);
            $t->string('status')->default('offline'); $t->timestamps();
        });
    }

    public function test_new_rows_are_online_when_the_company_has_no_hr_attendance(): void
    {
        $this->assertSame('online', StaffAvailability::ensureExists(1, 10)->status);
        $this->assertTrue(StaffAvailability::find(1)->isEffectivelyAvailable());
    }

    public function test_hr_settings_alone_do_not_make_staff_offline_until_attendance_is_actually_used(): void
    {
        Schema::create('hr_settings', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('company_id'); $t->boolean('auto_availability')->default(true); });
        Schema::create('hr_attendance', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('company_id'); });
        DB::table('hr_settings')->insert(['company_id' => 1]);
        $this->assertSame('online', StaffAvailability::ensureExists(1, 11)->status, 'HR page opened but nobody has ever clocked in');
    }

    public function test_a_company_driven_by_attendance_starts_offline(): void
    {
        Schema::create('hr_settings', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('company_id'); $t->boolean('auto_availability')->default(true); });
        Schema::create('hr_attendance', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('company_id'); });
        DB::table('hr_settings')->insert(['company_id' => 1]);
        DB::table('hr_attendance')->insert(['company_id' => 1]);
        $this->assertSame('offline', StaffAvailability::ensureExists(1, 12)->status);
        // …but another company is unaffected
        $this->assertSame('online', StaffAvailability::ensureExists(2, 13)->status);
    }

    public function test_attendance_with_auto_availability_off_keeps_the_default_online(): void
    {
        Schema::create('hr_settings', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('company_id'); $t->boolean('auto_availability')->default(true); });
        Schema::create('hr_attendance', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('company_id'); });
        DB::table('hr_settings')->insert(['company_id' => 1, 'auto_availability' => false]);
        DB::table('hr_attendance')->insert(['company_id' => 1]);
        $this->assertSame('online', StaffAvailability::ensureExists(1, 14)->status);
    }

    public function test_an_existing_row_is_never_changed_by_ensure_exists(): void
    {
        StaffAvailability::create(['company_id' => 1, 'staff_id' => 15, 'status' => 'away', 'is_available' => false]);
        $r = StaffAvailability::ensureExists(1, 15);
        $this->assertSame('away', $r->status);
        $this->assertFalse($r->isEffectivelyAvailable());
    }
}
