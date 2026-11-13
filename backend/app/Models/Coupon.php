<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Coupon extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'code', 'name', 'description', 'discount_type', 'discount_value', 'max_discount', 'min_amount',
        'applies_to', 'plan_ids', 'addon_ids', 'durations', 'first_purchase_only', 'company_id',
        'max_redemptions', 'per_company_limit', 'starts_at', 'expires_at', 'is_active', 'created_by',
    ];

    protected $casts = [
        'applies_to' => 'array', 'plan_ids' => 'array', 'addon_ids' => 'array', 'durations' => 'array',
        'discount_value' => 'decimal:2', 'max_discount' => 'decimal:2', 'min_amount' => 'decimal:2',
        'first_purchase_only' => 'boolean', 'is_active' => 'boolean',
        'starts_at' => 'datetime', 'expires_at' => 'datetime',
    ];

    public function redemptions(): HasMany { return $this->hasMany(CouponRedemption::class); }

    public static function normalize(string $code): string { return strtoupper(trim($code)); }
}
