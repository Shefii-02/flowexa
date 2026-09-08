<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MetaAdAccount extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'meta_ad_accounts';

    protected $guarded = [];

    protected $casts = [
        // Encrypted at rest — the long-lived user token is the keys to the company's ad spend.
        // Backfilled by the 2026_09_09_000003 migration; new writes encrypt automatically.
        'access_token'     => 'encrypted',
        'is_active'        => 'boolean',
        'is_default'       => 'boolean',
        'token_expires_at' => 'datetime',
        'last_synced_at'   => 'datetime',
        'created_at'       => 'datetime',
        'updated_at'       => 'datetime',
        'deleted_at'       => 'datetime',
    ];

    // The long-lived user token is a credential — keep it out of every API response by default.
    protected $hidden = ['access_token'];

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function campaigns(): HasMany { return $this->hasMany(MetaCampaign::class, 'meta_ad_account_id'); }
    public function adSets(): HasMany { return $this->hasMany(MetaAdSet::class, 'meta_ad_account_id'); }
    public function creatives(): HasMany { return $this->hasMany(MetaAdCreative::class, 'meta_ad_account_id'); }
    public function media(): HasMany { return $this->hasMany(MetaMediaLibrary::class, 'meta_ad_account_id'); }
    public function audienceSets(): HasMany { return $this->hasMany(MetaAudienceSet::class, 'meta_ad_account_id'); }
}
