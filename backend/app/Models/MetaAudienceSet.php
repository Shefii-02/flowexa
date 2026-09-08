<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A company-owned, reusable audience definition — "save your targeting once, apply it in one click".
 *
 * Stored in our own normalized shape (age/genders/geo/interests/behaviors/placements/…) so the
 * ad-set builder can pre-fill an editable form ("customize a copy"), while `targeting_spec` caches
 * the compiled Meta targeting object for a direct one-click apply. System-provided starting points
 * live in `meta_audience_templates`; once a company saves or customizes one it becomes a row here.
 */
class MetaAudienceSet extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'meta_audience_sets';

    protected $guarded = [];

    protected $casts = [
        'age_min'                   => 'integer',
        'age_max'                   => 'integer',
        'geo_locations'             => 'array',
        'interests'                 => 'array',
        'behaviors'                 => 'array',
        'locales'                   => 'array',
        'custom_audiences'          => 'array',
        'excluded_custom_audiences' => 'array',
        'flexible_spec'             => 'array',
        'exclusions'                => 'array',
        'placements'                => 'array',
        'targeting_spec'            => 'array',
        'reach_min'                 => 'integer',
        'reach_max'                 => 'integer',
        'reach_estimated_at'        => 'datetime',
        'is_favorite'               => 'boolean',
        'use_count'                 => 'integer',
        'last_used_at'              => 'datetime',
        'created_at'                => 'datetime',
        'updated_at'                => 'datetime',
        'deleted_at'                => 'datetime',
    ];

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function template(): BelongsTo { return $this->belongsTo(MetaAudienceTemplate::class, 'template_id'); }
    public function adAccount(): BelongsTo { return $this->belongsTo(MetaAdAccount::class, 'meta_ad_account_id'); }
    public function adSets(): HasMany { return $this->hasMany(MetaAdSet::class, 'audience_set_id'); }
}
