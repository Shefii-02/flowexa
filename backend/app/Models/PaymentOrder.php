<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;


// ════════════════════════════════════════════════════════════════════════════
// PaymentOrder
// ════════════════════════════════════════════════════════════════════════════
class PaymentOrder extends Model
{
    protected $fillable = [
        'company_id', 'user_id', 'razorpay_order_id',
        'razorpay_payment_id', 'razorpay_signature',
        'amount', 'messages_credit', 'status',
        'purpose', 'gateway', 'method', 'subtotal', 'discount', 'coupon_id', 'coupon_code', 'meta', 'paid_at',
    ];

    protected $casts = ['meta' => 'array', 'paid_at' => 'datetime', 'amount' => 'decimal:2', 'subtotal' => 'decimal:2', 'discount' => 'decimal:2'];

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function user(): BelongsTo    { return $this->belongsTo(User::class); }

    public function scopePending($q) { return $q->where('status', 'pending'); }
    public function scopePaid($q)    { return $q->where('status', 'paid'); }
}
