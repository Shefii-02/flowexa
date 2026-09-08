<?php

namespace App\Modules\Hr\Models;

use Illuminate\Database\Eloquent\Model;

class HrIncentiveRule extends Model
{
    protected $table = 'hr_incentive_rules';

    protected $fillable = ['company_id', 'name', 'category', 'kind', 'percent', 'fixed_amount', 'sort_order', 'is_active'];

    protected $casts = ['percent' => 'float', 'fixed_amount' => 'float', 'is_active' => 'boolean'];

    /** Incentive amount this rule yields for a given sale value. */
    public function amountFor(float $saleValue): float
    {
        return $this->kind === 'fixed'
            ? (float) $this->fixed_amount
            : round($saleValue * ((float) $this->percent) / 100, 2);
    }
}
