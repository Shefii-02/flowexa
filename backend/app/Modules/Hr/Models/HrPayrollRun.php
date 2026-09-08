<?php

namespace App\Modules\Hr\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrPayrollRun extends Model
{
    protected $table = 'hr_payroll_runs';

    protected $fillable = ['company_id', 'period', 'status', 'totals', 'notes', 'generated_by', 'released_by', 'released_at'];

    protected $casts = ['totals' => 'array', 'released_at' => 'datetime'];

    public function items(): HasMany { return $this->hasMany(HrPayrollItem::class, 'payroll_run_id'); }

    public function isReleased(): bool { return $this->status === 'released'; }
}
