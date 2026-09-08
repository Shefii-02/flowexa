<?php

namespace App\Modules\Hr\Models;

use Illuminate\Database\Eloquent\Model;

class HrLeaveType extends Model
{
    protected $table = 'hr_leave_types';

    protected $fillable = [
        'company_id', 'name', 'is_paid', 'max_days_per_year',
        'requires_approval', 'color', 'sort_order', 'is_active',
    ];

    protected $casts = [
        'is_paid'           => 'boolean',
        'requires_approval' => 'boolean',
        'is_active'         => 'boolean',
        'max_days_per_year' => 'integer',
    ];
}
