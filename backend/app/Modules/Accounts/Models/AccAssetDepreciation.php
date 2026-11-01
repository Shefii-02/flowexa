<?php

namespace App\Modules\Accounts\Models;

use Illuminate\Database\Eloquent\Model;

class AccAssetDepreciation extends Model
{
    protected $table = 'acc_asset_depreciations';
    protected $fillable = ['company_id', 'asset_id', 'period_end', 'amount', 'voucher_id'];
    protected $casts = ['period_end' => 'date:Y-m-d', 'amount' => 'float'];
}
