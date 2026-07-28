<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// ════════════════════════════════════════════════════════════════════════════
// Wallet
// ════════════════════════════════════════════════════════════════════════════
class Wallet extends Model
{
    protected $fillable = [
        'company_id', 'balance', 'total_used', 'total_purchased',
        'free_quota_used', 'low_balance_alert', 'auto_recharge',
        'auto_recharge_amount', 'auto_recharge_threshold', 'free_quota_reset_at',
    ];

    protected $casts = [
        'auto_recharge'      => 'boolean',
        'free_quota_reset_at'=> 'datetime',
    ];

    public function company(): BelongsTo   { return $this->belongsTo(Company::class); }
    public function transactions(): HasMany{ return $this->hasMany(WalletTransaction::class, 'company_id', 'company_id'); }

    public function getIsLowAttribute(): bool
    {
        return $this->balance <= $this->low_balance_alert;
    }
}
