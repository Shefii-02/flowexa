<?php

namespace App\Modules\Hr\Support;

use App\Models\CompanyHoliday;
use App\Models\CompanyWorkingHour;
use App\Modules\Hr\Models\HrAttendance;
use App\Modules\Hr\Models\HrIncentive;
use App\Modules\Hr\Models\HrLeaveRequest;
use App\Modules\Hr\Models\HrPayrollItem;
use App\Modules\Hr\Models\HrPayrollRun;
use App\Modules\Hr\Models\HrSetting;
use App\Modules\Hr\Models\HrStaffProfile;
use Illuminate\Support\Carbon;

/**
 * Builds a monthly payroll run: pulls attendance, leave and incentives for the
 * period and computes each staff member's pay. Every number is stored so an
 * admin can see how it was derived and override individual lines.
 */
class PayrollService
{
    public function generate(int $companyId, string $period, ?int $generatedBy = null): HrPayrollRun
    {
        $month = Carbon::createFromFormat('Y-m', $period)->startOfMonth();
        $end   = $month->copy()->endOfMonth();
        $settings = HrSetting::forCompany($companyId);

        $run = HrPayrollRun::firstOrCreate(
            ['company_id' => $companyId, 'period' => $period],
            ['status' => 'draft', 'generated_by' => $generatedBy],
        );

        if ($run->isReleased()) {
            return $run->load('items.user:id,name,department');
        }

        // released runs untouched; a re-generate rebuilds draft items
        $run->items()->delete();

        $workingDaysElapsed = $this->workingDaysElapsed($companyId, $month, $end, $settings);

        foreach (HrStaffProfile::where('company_id', $companyId)->where('is_active', true)->with('user:id,name,department')->get() as $profile) {
            $attrs = $this->computeItem($profile, $month, $end, $settings, $workingDaysElapsed, $run->id);
            $item = $run->items()->create($attrs);

            // Attach the month's incentives to this pay line so "release" can pay them.
            $ids = $attrs['computed']['incentive_ids'] ?? [];
            if ($ids) {
                HrIncentive::whereIn('id', $ids)->update(['payroll_item_id' => $item->id, 'status' => 'approved']);
            }
        }

        $run->update(['totals' => $this->totals($run)]);

        return $run->fresh()->load('items.user:id,name,department');
    }

    public function release(HrPayrollRun $run, ?int $releasedBy = null): HrPayrollRun
    {
        $run->update(['status' => 'released', 'released_by' => $releasedBy, 'released_at' => now()]);

        // Freeze the incentives that fed this run.
        HrIncentive::whereIn('payroll_item_id', $run->items()->pluck('id'))->update(['status' => 'paid']);

        return $run->fresh()->load('items.user:id,name,department');
    }

    /** @return array<string, mixed> */
    private function computeItem(HrStaffProfile $profile, Carbon $month, Carbon $end, HrSetting $settings, int $workingDaysElapsed, int $runId): array
    {
        $companyId = $profile->company_id;
        $userId = $profile->user_id;

        $att = HrAttendance::where('company_id', $companyId)->where('user_id', $userId)
            ->whereBetween('work_date', [$month, $end])->get();

        $present   = $att->where('status', 'present')->count();
        $lateDays  = $att->where('late_minutes', '>', 0)->count();
        $worked    = round($att->sum('worked_minutes') / 60, 2);
        $otHours   = round($att->where('overtime_status', 'approved')->sum('overtime_minutes') / 60, 2);

        // Leave days for the month, split paid / unpaid by leave type.
        $leaves = HrLeaveRequest::where('company_id', $companyId)->where('user_id', $userId)
            ->where('status', 'approved')
            ->where('start_date', '<=', $end)->where('end_date', '>=', $month)
            ->with('leaveType:id,is_paid')->get();

        $paidLeave = 0.0;
        $unpaidLeave = 0.0;
        foreach ($leaves as $lv) {
            $days = $this->leaveDaysInMonth($lv, $month, $end);
            $lv->leaveType?->is_paid ? $paidLeave += $days : $unpaidLeave += $days;
        }

        $absent = max(0, $workingDaysElapsed - $present - (int) ceil($paidLeave) - (int) ceil($unpaidLeave));

        $wd = max(1, $settings->payroll_working_days);
        $dayRate = $profile->monthly_salary ? round($profile->monthly_salary / $wd, 2) : null;
        $hourRate = $profile->hourly_rate ?? ($dayRate ? round($dayRate / 8, 2) : null);

        $basePay = $profile->monthly_salary !== null
            ? (float) $profile->monthly_salary
            : round($worked * ($hourRate ?? 0), 2);

        $otPay = $hourRate !== null ? round($otHours * $hourRate * $settings->overtime_multiplier, 2) : 0.0;

        // Incentives earned in the month → attach to this item.
        $incentives = HrIncentive::where('company_id', $companyId)->where('user_id', $userId)
            ->whereBetween('earned_on', [$month, $end])
            ->whereIn('status', ['pending', 'approved'])
            ->get();
        $incentivePay = round($incentives->sum('amount'), 2);

        $deductions = round($lateDays * (float) $settings->late_penalty_amount, 2);
        if ($settings->deduct_unpaid_leave && $dayRate) {
            $deductions += round($unpaidLeave * $dayRate, 2);
        }
        if ($settings->deduct_absent_days && $dayRate) {
            $deductions += round($absent * $dayRate, 2);
        }
        // hourly staff already lose absent/leave pay via worked hours
        if ($profile->monthly_salary === null) {
            $deductions = round($lateDays * (float) $settings->late_penalty_amount, 2);
        }

        $gross = round($basePay + $otPay + $incentivePay, 2);
        $net   = round($gross - $deductions, 2);

        return [
            'company_id'        => $companyId,
            'user_id'           => $userId,
            'present_days'      => $present,
            'paid_leave_days'   => $paidLeave,
            'unpaid_leave_days' => $unpaidLeave,
            'absent_days'       => $absent,
            'late_days'         => $lateDays,
            'worked_hours'      => $worked,
            'overtime_hours'    => $otHours,
            'base_pay'          => $basePay,
            'overtime_pay'      => $otPay,
            'incentive_pay'     => $incentivePay,
            'allowances'        => 0,
            'deductions'        => $deductions,
            'gross_pay'         => $gross,
            'net_pay'           => $net,
            'adjustments'       => [],
            'computed'          => [
                'working_days_elapsed' => $workingDaysElapsed,
                'day_rate'             => $dayRate,
                'hour_rate'            => $hourRate,
                'incentive_ids'        => $incentives->pluck('id')->all(),
                'late_penalty_each'    => (float) $settings->late_penalty_amount,
            ],
            'status'            => 'draft',
        ];
    }

    private function workingDaysElapsed(int $companyId, Carbon $month, Carbon $end, HrSetting $settings): int
    {
        $openWeekdays = CompanyWorkingHour::where('company_id', $companyId)->where('is_open', true)->pluck('weekday')->all();
        if (empty($openWeekdays)) {
            $openWeekdays = [1, 2, 3, 4, 5];
        }
        $holidays = CompanyHoliday::where('company_id', $companyId)
            ->whereBetween('date', [$month, $end])->pluck('date')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())->all();

        $stop = min($end->timestamp, now()->timestamp);
        $count = 0;
        for ($d = $month->copy(); $d->timestamp <= $stop; $d->addDay()) {
            if (in_array((int) $d->dayOfWeek, $openWeekdays, true) && !in_array($d->toDateString(), $holidays, true)) {
                $count++;
            }
        }
        return $count;
    }

    private function leaveDaysInMonth(HrLeaveRequest $lv, Carbon $month, Carbon $end): float
    {
        $from = $lv->start_date->greaterThan($month) ? $lv->start_date : $month;
        $to   = $lv->end_date->lessThan($end) ? $lv->end_date : $end;
        $days = $from->diffInDays($to) + 1;
        return $lv->half_day ? min(0.5, $days) : $days;
    }

    /** @return array<string, float> */
    private function totals(HrPayrollRun $run): array
    {
        $items = $run->items()->get();
        return [
            'staff'         => $items->count(),
            'base_pay'      => round($items->sum('base_pay'), 2),
            'overtime_pay'  => round($items->sum('overtime_pay'), 2),
            'incentive_pay' => round($items->sum('incentive_pay'), 2),
            'deductions'    => round($items->sum('deductions'), 2),
            'net_pay'       => round($items->sum('net_pay'), 2),
        ];
    }
}
