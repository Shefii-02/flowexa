<?php

namespace App\Modules\Accounts\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccPayrollLine extends Model
{
    protected $table = 'acc_payroll_lines';
    public $timestamps = false;
    protected $fillable = ['run_id', 'employee_id', 'basic', 'allowance', 'commission', 'unpaid_days', 'unpaid_deduction', 'other_deductions', 'net', 'leave_accrual', 'ticket_accrual', 'eos_accrual', 'notes'];
    protected $casts = ['basic' => 'float', 'allowance' => 'float', 'commission' => 'float', 'unpaid_days' => 'float', 'unpaid_deduction' => 'float', 'other_deductions' => 'float',
                        'net' => 'float', 'leave_accrual' => 'float', 'ticket_accrual' => 'float', 'eos_accrual' => 'float'];

    public function employee(): BelongsTo { return $this->belongsTo(AccEmployee::class, 'employee_id'); }
}
