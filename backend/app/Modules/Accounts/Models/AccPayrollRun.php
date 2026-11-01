<?php

namespace App\Modules\Accounts\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccPayrollRun extends Model
{
    protected $table = 'acc_payroll_runs';
    protected $fillable = ['company_id', 'period', 'status', 'total_gross', 'total_deductions', 'total_net', 'total_provisions', 'voucher_id', 'payment_voucher_id', 'paid_on', 'created_by'];
    protected $casts = ['paid_on' => 'date:Y-m-d', 'total_gross' => 'float', 'total_deductions' => 'float', 'total_net' => 'float', 'total_provisions' => 'float'];

    public function lines(): HasMany { return $this->hasMany(AccPayrollLine::class, 'run_id'); }
}
