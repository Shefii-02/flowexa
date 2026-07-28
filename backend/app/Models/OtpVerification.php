<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;


// ════════════════════════════════════════════════════════════════════════════
// OtpVerification
// ════════════════════════════════════════════════════════════════════════════
class OtpVerification extends Model
{
    protected $fillable = [
        'company_id', 'ref_id', 'phone', 'otp', 'device_id', 'is_used', 'expires_at',
    ];

    protected $hidden = ['otp'];

    protected $casts = [
        'is_used'    => 'boolean',
        'expires_at' => 'datetime',
    ];

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function scopeActive($q)
    {
        return $q->where('is_used', false)->where('expires_at', '>', now());
    }
}
