<?php

namespace App\Modules\Hr\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrPayrollItem extends Model
{
    protected $table = 'hr_payroll_items';

    protected $fillable = [
        'payroll_run_id', 'company_id', 'user_id',
        'present_days', 'paid_leave_days', 'unpaid_leave_days', 'absent_days', 'late_days',
        'worked_hours', 'overtime_hours',
        'base_pay', 'overtime_pay', 'incentive_pay', 'allowances', 'deductions', 'gross_pay', 'net_pay',
        'adjustments', 'computed', 'note', 'status',
    ];

    protected $casts = [
        'adjustments'    => 'array',
        'computed'       => 'array',
        'worked_hours'   => 'float',
        'overtime_hours' => 'float',
        'base_pay'       => 'float',
        'overtime_pay'   => 'float',
        'incentive_pay'  => 'float',
        'allowances'     => 'float',
        'deductions'     => 'float',
        'gross_pay'      => 'float',
        'net_pay'        => 'float',
        'paid_leave_days'   => 'float',
        'unpaid_leave_days' => 'float',
    ];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function run(): BelongsTo { return $this->belongsTo(HrPayrollRun::class, 'payroll_run_id'); }

    /** Recompute gross/net from the component columns + adjustments. */
    public function recompute(): void
    {
        $adj = collect($this->adjustments ?? [])->sum(fn ($a) => (float) ($a['amount'] ?? 0));
        $gross = $this->base_pay + $this->overtime_pay + $this->incentive_pay + $this->allowances + $adj;
        $this->gross_pay = round($gross, 2);
        $this->net_pay = round($gross - $this->deductions, 2);
    }
}
