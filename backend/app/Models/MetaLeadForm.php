<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MetaLeadForm extends Model
{
    use HasFactory;

    protected $table = 'meta_lead_forms';
    protected $guarded = [];

    protected $casts = [
        'questions'       => 'array',
        'privacy_policy'  => 'array',
        'leads_count'     => 'integer',
        'last_synced_at'  => 'datetime',
        'created_at'      => 'datetime',
        'updated_at'      => 'datetime',
    ];

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function adAccount(): BelongsTo { return $this->belongsTo(MetaAdAccount::class, 'meta_ad_account_id'); }
    public function leads(): HasMany { return $this->hasMany(MetaLead::class, 'meta_lead_form_id'); }
}
