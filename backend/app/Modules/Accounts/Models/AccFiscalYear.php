<?php

namespace App\Modules\Accounts\Models;

use Illuminate\Database\Eloquent\Model;

class AccFiscalYear extends Model
{
    protected $table = 'acc_fiscal_years';
    protected $fillable = ['company_id', 'name', 'start_date', 'end_date', 'status', 'closed_at', 'closed_by', 'closing_voucher_id', 'net_profit'];
    protected $casts = ['start_date' => 'date:Y-m-d', 'end_date' => 'date:Y-m-d', 'closed_at' => 'datetime', 'net_profit' => 'float'];
}
