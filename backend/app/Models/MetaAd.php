<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MetaAd extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'meta_ads';

    protected $guarded = [];

    protected $casts = [
        'meta_response' => 'array',
        'published_at'  => 'datetime',
        'created_at'    => 'datetime',
        'updated_at'    => 'datetime',
        'deleted_at'    => 'datetime',
    ];

    protected $hidden = ['meta_response'];

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function adSet(): BelongsTo { return $this->belongsTo(MetaAdSet::class, 'meta_ad_set_id'); }
    public function creative(): BelongsTo { return $this->belongsTo(MetaAdCreative::class, 'meta_ad_creative_id'); }

    public function insights(): HasMany
    {
        return $this->hasMany(MetaInsight::class, 'object_id')->where('object_type', 'ad');
    }
}
