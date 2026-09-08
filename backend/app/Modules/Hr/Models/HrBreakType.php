<?php

namespace App\Modules\Hr\Models;

use Illuminate\Database\Eloquent\Model;

class HrBreakType extends Model
{
    protected $table = 'hr_break_types';

    protected $fillable = [
        'company_id', 'name', 'max_minutes', 'daily_limit',
        'is_paid', 'requires_gps', 'sort_order', 'is_active',
    ];

    protected $casts = [
        'max_minutes'  => 'integer',
        'daily_limit'  => 'integer',
        'is_paid'      => 'boolean',
        'requires_gps' => 'boolean',
        'is_active'    => 'boolean',
    ];
}
