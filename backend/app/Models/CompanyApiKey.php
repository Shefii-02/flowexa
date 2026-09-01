<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyApiKey extends Model
{
    protected $fillable = [
        'company_id',
        'provider',
        'key_label',
        'api_key',         // encrypted value — never returned to frontend
        'api_key_hint',
        'is_active',
        'is_verified',
        'last_verified_at',
        'last_used_at',
        'usage_count',
        'monthly_limit_usd',
        'monthly_used_usd',
        'meta',
        'created_by',
    ];

    protected $hidden = ['api_key'];  // never serialised to JSON responses

    protected $casts = [
        'is_active'        => 'boolean',
        'is_verified'      => 'boolean',
        'last_verified_at' => 'datetime',
        'last_used_at'     => 'datetime',
        'monthly_limit_usd'=> 'float',
        'monthly_used_usd' => 'float',
        'meta'             => 'array',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function isAtLimit(): bool
    {
        if (!$this->monthly_limit_usd) return false;
        return $this->monthly_used_usd >= $this->monthly_limit_usd;
    }

    public function usagePercent(): float
    {
        if (!$this->monthly_limit_usd) return 0.0;
        return min(round(($this->monthly_used_usd / $this->monthly_limit_usd) * 100, 1), 100.0);
    }
}
