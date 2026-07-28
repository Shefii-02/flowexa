<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;


// ════════════════════════════════════════════════════════════════════════════
// Addon
// ════════════════════════════════════════════════════════════════════════════
class Addon extends Model
{
    protected $fillable = [
        'name','slug','description','type','price','billing_cycle','config','is_active',
    ];

    protected $casts = [
        'config'    => 'array',
        'is_active' => 'boolean',
        'price'     => 'decimal:2',
    ];

    public function companyAddons(): HasMany { return $this->hasMany(CompanyAddon::class); }

    public function scopeActive($q) { return $q->where('is_active', true); }
}
