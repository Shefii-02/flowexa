<?php

namespace App\Modules\Accounts\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccAsset extends Model
{
    protected $table = 'acc_assets';
    protected $fillable = [
        'company_id', 'tag', 'name', 'category', 'purchase_date', 'cost', 'salvage_value', 'useful_life_months', 'method', 'wdv_rate',
        'opening_accumulated', 'accumulated', 'opening_to', 'last_depreciated_to', 'status', 'disposed_on', 'disposal_proceeds', 'disposal_gain_loss',
        'acquisition_voucher_id', 'disposal_voucher_id', 'location', 'notes',
    ];
    protected $casts = [
        'purchase_date' => 'date:Y-m-d', 'opening_to' => 'date:Y-m-d', 'last_depreciated_to' => 'date:Y-m-d', 'disposed_on' => 'date:Y-m-d',
        'cost' => 'float', 'salvage_value' => 'float', 'wdv_rate' => 'float', 'opening_accumulated' => 'float', 'accumulated' => 'float',
        'disposal_proceeds' => 'float', 'disposal_gain_loss' => 'float', 'useful_life_months' => 'integer',
    ];

    public function depreciations(): HasMany { return $this->hasMany(AccAssetDepreciation::class, 'asset_id'); }
}
