<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// ════════════════════════════════════════════════════════════════════════════
// CompanyPlan — plan purchase/subscription history
// ════════════════════════════════════════════════════════════════════════════
class CompanyPlan extends Model
{
    protected $fillable = [
        'company_id','plan_id','payment_order_id','duration_type',
        'duration_months','amount_paid','status','starts_at',
        'expires_at','cancelled_at','notes',
    ];

    protected $casts = [
        'starts_at'    => 'datetime',
        'expires_at'   => 'datetime',
        'cancelled_at' => 'datetime',
        'amount_paid'  => 'decimal:2',
    ];

    public function company(): BelongsTo      { return $this->belongsTo(Company::class); }
    public function plan(): BelongsTo         { return $this->belongsTo(Plan::class); }
    public function paymentOrder(): BelongsTo { return $this->belongsTo(PaymentOrder::class); }

    public function isActive(): bool
    {
        return $this->status === 'active' && (!$this->expires_at || $this->expires_at->isFuture());
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    public function scopeActive($q)  { return $q->where('status', 'active'); }
    public function scopeExpired($q) { return $q->where('expires_at', '<', now()); }
}
