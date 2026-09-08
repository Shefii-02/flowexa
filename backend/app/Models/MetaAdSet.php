<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MetaAdSet extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'meta_ad_sets';

    protected $guarded = [];

    protected $casts = [
        'daily_budget'    => 'decimal:2',
        'lifetime_budget' => 'decimal:2',
        'bid_amount'      => 'decimal:2',
        'targeting'       => 'array',
        'placements'      => 'array',
        'meta_response'   => 'array',
        'start_time'      => 'datetime',
        'end_time'        => 'datetime',
        'created_at'      => 'datetime',
        'updated_at'      => 'datetime',
        'deleted_at'      => 'datetime',
    ];

    protected $hidden = ['meta_response'];

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function campaign(): BelongsTo { return $this->belongsTo(MetaCampaign::class, 'meta_campaign_id'); }
    public function adAccount(): BelongsTo { return $this->belongsTo(MetaAdAccount::class, 'meta_ad_account_id'); }
    public function audienceTemplate(): BelongsTo { return $this->belongsTo(MetaAudienceTemplate::class, 'audience_template_id'); }
    public function audienceSet(): BelongsTo { return $this->belongsTo(MetaAudienceSet::class, 'audience_set_id'); }
    public function ads(): HasMany { return $this->hasMany(MetaAd::class, 'meta_ad_set_id'); }
}
