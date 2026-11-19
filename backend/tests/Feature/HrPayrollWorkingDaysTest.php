<?php

namespace Tests\Feature;

use App\Modules\Hr\Support\PayrollService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class HrPayrollWorkingDaysTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('company_working_hours', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedTinyInteger('weekday'); $t->boolean('is_open')->default(true); $t->string('start_time')->nullable(); $t->string('end_time')->nullable(); $t->timestamps(); });
        Schema::create('company_holidays', function (Blueprint $t) { $t->id(); $t->unsignedBigInteger('company_id'); $t->date('date'); $t->string('name')->nullable(); $t->timestamps(); });
        Carbon::setTestNow('2026-11-30 18:00:00');
    }

    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    private function days(string $from): int
    {
        $month = Carbon::parse('2026-11-01')->startOfMonth();
        $r = new \ReflectionMethod(PayrollService::class, 'workingDaysElapsed');
        $r->setAccessible(true);
        return $r->invoke(new PayrollService(), 1, Carbon::parse($from)->startOfDay(), $month->copy()->endOfMonth(), new \App\Modules\Hr\Models\HrSetting());
    }

    public function test_full_month_counts_weekdays(): void
    {
        $this->assertSame(21, $this->days('2026-11-01'));   // Nov 2026: 21 Mon–Fri
    }

    public function test_mid_month_joiner_is_only_counted_from_joining_day(): void
    {
        $this->assertSame(11, $this->days('2026-11-16'));   // 16..30 Nov = 11 weekdays
    }
}
