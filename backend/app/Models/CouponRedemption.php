<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CouponRedemption extends Model
{
    protected $fillable = ['coupon_id', 'company_id', 'payment_order_id', 'purpose', 'email', 'subtotal', 'discount', 'final_amount'];
    protected $casts = ['subtotal' => 'decimal:2', 'discount' => 'decimal:2', 'final_amount' => 'decimal:2'];

    public function coupon(): BelongsTo  { return $this->belongsTo(Coupon::class)->withTrashed(); }
    public function company(): BelongsTo { return $this->belongsTo(Company::class)->withTrashed(); }
}
