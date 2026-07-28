<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// ════════════════════════════════════════════════════════════════════════════
// WalletTransaction
// ════════════════════════════════════════════════════════════════════════════
class WalletTransaction extends Model
{
    protected $fillable = [
        'company_id', 'user_id', 'type', 'amount',
        'balance_before', 'balance_after', 'description',
        'reference_id', 'reference_type',
    ];

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function user(): BelongsTo    { return $this->belongsTo(User::class); }

    public function scopeCredits($q) { return $q->where('type', 'credit'); }
    public function scopeDebits($q)  { return $q->where('type', 'debit'); }
}
