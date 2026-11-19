<?php

namespace App\Modules\Hr\Models;

use Illuminate\Database\Eloquent\Model;

/** Daily / monthly sales-activity targets. user_id null = the company default; a per-employee row overrides it field by field. */
class SalesActivityTarget extends Model
{
    protected $table = 'sales_activity_targets';

    public const FIELDS = ['daily_new_lead_calls', 'daily_follow_up_calls', 'monthly_calls', 'monthly_conversions', 'max_cost_per_conversion'];

    protected $fillable = ['company_id', 'user_id', ...self::FIELDS, 'default_lead_cost'];

    protected $casts = [
        'daily_new_lead_calls' => 'integer', 'daily_follow_up_calls' => 'integer',
        'monthly_calls' => 'integer', 'monthly_conversions' => 'integer',
        'max_cost_per_conversion' => 'float', 'default_lead_cost' => 'float',
    ];

    public static function defaultFor(int $companyId): self
    {
        return static::firstOrNew(['company_id' => $companyId, 'user_id' => null]);
    }
}
