<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MetaAdCreative extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'meta_ad_creatives';

    protected $guarded = [];

    protected $casts = [
        'carousel_cards' => 'array',
        'meta_response'  => 'array',
        'created_at'     => 'datetime',
        'updated_at'     => 'datetime',
        'deleted_at'     => 'datetime',
    ];

    protected $hidden = ['meta_response'];

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function adAccount(): BelongsTo { return $this->belongsTo(MetaAdAccount::class, 'meta_ad_account_id'); }
    public function image(): BelongsTo { return $this->belongsTo(MetaMediaLibrary::class, 'image_id'); }
    public function video(): BelongsTo { return $this->belongsTo(MetaMediaLibrary::class, 'video_id'); }
    public function leadForm(): BelongsTo { return $this->belongsTo(MetaLeadForm::class, 'lead_form_id'); }
    public function ads(): HasMany { return $this->hasMany(MetaAd::class, 'meta_ad_creative_id'); }
}
