<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;


// ════════════════════════════════════════════════════════════════════════════
// TopupPackage
// ════════════════════════════════════════════════════════════════════════════
class TopupPackage extends Model
{
    protected $fillable = ['messages','price','label','is_popular','is_active','sort_order'];

    protected $casts = [
        'is_popular' => 'boolean',
        'is_active'  => 'boolean',
        'price'      => 'decimal:2',
    ];

    public function scopeActive($q)  { return $q->where('is_active', true)->orderBy('sort_order'); }
    public function scopePopular($q) { return $q->where('is_popular', true); }
}
