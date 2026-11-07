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
        'name','slug','description','type','price','price_yearly','billing_cycle','config','is_active',
    ];

    protected $appends = ['group'];

    protected $casts = [
        'config'    => 'array',
        'is_active' => 'boolean',
        'price'     => 'decimal:2',
        'price_yearly' => 'decimal:2',
    ];

    /** Which feature group this add-on belongs to (for the grouped list); non-limit add-ons go under "Other". */
    public function getGroupAttribute(): array
    {
        $g = ($this->type === 'limit' && !empty($this->config['resource'])) ? \App\Support\PlanLimits::groupKeyOf($this->config['resource']) : null;
        return $g ? ['key' => $g, 'title' => \App\Support\PlanLimits::GROUPS[$g][0], 'icon' => \App\Support\PlanLimits::GROUPS[$g][1]] : ['key' => 'other', 'title' => 'Other add-ons', 'icon' => '✨'];
    }

    public function companyAddons(): HasMany { return $this->hasMany(CompanyAddon::class); }

    public function scopeActive($q) { return $q->where('is_active', true); }
}
