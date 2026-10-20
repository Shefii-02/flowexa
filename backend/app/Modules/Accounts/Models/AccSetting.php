<?php

namespace App\Modules\Accounts\Models;

use Illuminate\Database\Eloquent\Model;

class AccSetting extends Model
{
    protected $table = 'acc_settings';
    protected $fillable = ['company_id', 'legal_name', 'gstin', 'pan', 'state_code', 'address', 'invoice_prefix', 'fy_start_month', 'gst_registered'];
    protected $casts = ['gst_registered' => 'boolean', 'fy_start_month' => 'integer'];
}
