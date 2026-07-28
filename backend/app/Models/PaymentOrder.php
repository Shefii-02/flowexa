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
    ];

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function user(): BelongsTo    { return $this->belongsTo(User::class); }

    public function scopePending($q) { return $q->where('status', 'pending'); }
    public function scopePaid($q)    { return $q->where('status', 'paid'); }
}
