<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// ════════════════════════════════════════════════════════════════════════════
// WaPhoneNumber — multi-number per company
// ════════════════════════════════════════════════════════════════════════════
class WaPhoneNumber extends Model
{
    protected $fillable = [
        'company_id','label','phone_number_id','access_token',
        'business_account_id','display_number','is_active',
        'is_default','status','last_error','last_verified_at',
    ];

    protected $hidden = ['access_token'];

    protected $casts = [
        'is_active'        => 'boolean',
        'is_default'       => 'boolean',
        'last_verified_at' => 'datetime',
    ];

    public function company(): BelongsTo   { return $this->belongsTo(Company::class); }
    public function templates(): HasMany   { return $this->hasMany(WaTemplate::class); }
    public function campaigns(): HasMany   { return $this->hasMany(Campaign::class); }

    public function getDecryptedTokenAttribute(): string
    {
        return decrypt($this->access_token);
    }

    public function scopeActive($q)   { return $q->where('is_active', true); }
    public function scopeDefault($q)  { return $q->where('is_default', true); }
}
