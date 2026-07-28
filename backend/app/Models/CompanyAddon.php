<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;


// ════════════════════════════════════════════════════════════════════════════
// CompanyAddon
// ════════════════════════════════════════════════════════════════════════════
class CompanyAddon extends Model
{
    protected $fillable = [
        'company_id','addon_id','payment_order_id','amount_paid',
        'status','starts_at','expires_at',
    ];

    protected $casts = [
        'starts_at'  => 'datetime',
        'expires_at' => 'datetime',
        'amount_paid'=> 'decimal:2',
    ];

    public function company(): BelongsTo      { return $this->belongsTo(Company::class); }
    public function addon(): BelongsTo        { return $this->belongsTo(Addon::class); }
    public function paymentOrder(): BelongsTo { return $this->belongsTo(PaymentOrder::class); }

    public function isActive(): bool
    {
        return $this->status === 'active' && (!$this->expires_at || $this->expires_at->isFuture());
    }
}
