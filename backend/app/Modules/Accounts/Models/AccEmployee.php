<?php

namespace App\Modules\Accounts\Models;

use Illuminate\Database\Eloquent\Model;

class AccEmployee extends Model
{
    protected $table = 'acc_employees';
    protected $fillable = [
        'company_id', 'user_id', 'code', 'name', 'designation', 'joined_on', 'left_on', 'status', 'basic', 'allowance', 'annual_ticket',
        'leave_days_per_year', 'eos_days_per_year', 'leave_taken_days', 'ticket_used', 'settled_on', 'settlement_amount', 'settlement_voucher_id', 'notes',
    ];
    protected $casts = [
        'joined_on' => 'date:Y-m-d', 'left_on' => 'date:Y-m-d', 'settled_on' => 'date:Y-m-d', 'basic' => 'float', 'allowance' => 'float', 'annual_ticket' => 'float',
        'leave_days_per_year' => 'float', 'eos_days_per_year' => 'float', 'leave_taken_days' => 'float', 'ticket_used' => 'float', 'settlement_amount' => 'float',
    ];
}
