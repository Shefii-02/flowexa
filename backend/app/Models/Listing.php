<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A catalog item the AI agent answers questions about and matches leads against — a property, a
 * clinic service, a course, or a generic product. Vertical-specific fields live in `attributes`,
 * keyed per config/industry_templates.php.
 */
class Listing extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'listings';
    protected $guarded = [];

    protected $casts = [
        'attributes' => 'array',
        'media'      => 'array',
        'price'      => 'decimal:2',
        'sort_order' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('status', 'active');
    }

    /** A one-line summary the agent can quote. */
    public function summaryLine(): string
    {
        $bits = [$this->title];
        if ($this->location) $bits[] = $this->location;
        if ($this->price) $bits[] = number_format((float) $this->price) . ' ' . $this->currency . ($this->price_unit ? " ({$this->price_unit})" : '');
        return implode(' · ', $bits);
    }
}
