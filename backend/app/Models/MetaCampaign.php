<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MetaCampaign extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'meta_campaigns';

    protected $guarded = [];

    protected $casts = [
        'special_ad_category'         => 'boolean',
        'special_ad_category_country' => 'array',
        'special_ad_categories'       => 'array',
        'spend_cap'                   => 'decimal:2',
        'meta_response'               => 'array',
        'started_at'                  => 'datetime',
        'stopped_at'                  => 'datetime',
        'created_at'                  => 'datetime',
        'updated_at'                  => 'datetime',
        'deleted_at'                  => 'datetime',
    ];

    protected $hidden = ['meta_response'];

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function adAccount(): BelongsTo { return $this->belongsTo(MetaAdAccount::class, 'meta_ad_account_id'); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function adSets(): HasMany { return $this->hasMany(MetaAdSet::class, 'meta_campaign_id'); }

    public function insights(): HasMany
    {
        return $this->hasMany(MetaInsight::class, 'object_id')->where('object_type', 'campaign');
    }
}
