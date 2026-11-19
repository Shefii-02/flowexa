<?php

namespace Tests\Unit;

use App\Modules\Hr\Models\HrLeaveRequest;
use App\Modules\Hr\Support\PayrollService;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class HrPayrollLeaveDaysTest extends TestCase
{
    private function days(string $start, string $end, string $month, bool $half = false): float
    {
        $lv = (new HrLeaveRequest())->forceFill(['start_date' => Carbon::parse($start), 'end_date' => Carbon::parse($end), 'half_day' => $half]);
        $m = Carbon::createFromFormat('Y-m', $month)->startOfMonth();
        $r = new \ReflectionMethod(PayrollService::class, 'leaveDaysInMonth');
        $r->setAccessible(true);
        return (float) $r->invoke(new PayrollService(), $lv, $m, $m->copy()->endOfMonth());
    }

    public function test_leave_inside_the_month_counts_whole_days(): void
    {
        $this->assertSame(3.0, $this->days('2026-11-10', '2026-11-12', '2026-11'));
    }

    public function test_leave_running_past_month_end_counts_only_this_months_days_as_whole_numbers(): void
    {
        $this->assertSame(6.0, $this->days('2026-11-25', '2026-12-05', '2026-11'));   // 25..30 Nov
    }

    public function test_leave_started_in_previous_month_counts_from_the_first(): void
    {
        $this->assertSame(5.0, $this->days('2026-10-28', '2026-11-05', '2026-11'));   // 1..5 Nov
    }

    public function test_half_day_is_half(): void
    {
        $this->assertSame(0.5, $this->days('2026-11-10', '2026-11-10', '2026-11', true));
    }
}
