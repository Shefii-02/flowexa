<?php

namespace App\Modules\Accounts\Models;

use Illuminate\Database\Eloquent\Model;

class AccSetting extends Model
{
    protected $table = 'acc_settings';
    protected $fillable = ['company_id', 'legal_name', 'gstin', 'pan', 'state_code', 'address', 'pincode', 'city', 'invoice_prefix', 'fy_start_month', 'gst_registered', 'chart_version', 'locked_until', 'petty_cash_limit', 'quotation_validity_days'];
    protected $casts = ['gst_registered' => 'boolean', 'fy_start_month' => 'integer', 'locked_until' => 'date:Y-m-d', 'petty_cash_limit' => 'float'];
}
