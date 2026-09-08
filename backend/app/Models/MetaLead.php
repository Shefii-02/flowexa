<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MetaLead extends Model
{
    use HasFactory;

    protected $table = 'meta_leads';
    protected $guarded = [];

    protected $casts = [
        'field_data'        => 'array',
        'meta_created_time' => 'datetime',
        'processed_at'      => 'datetime',
        'created_at'        => 'datetime',
        'updated_at'        => 'datetime',
    ];

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function form(): BelongsTo { return $this->belongsTo(MetaLeadForm::class, 'meta_lead_form_id'); }
    public function ad(): BelongsTo { return $this->belongsTo(MetaAd::class, 'meta_ad_id'); }
    public function campaign(): BelongsTo { return $this->belongsTo(MetaCampaign::class, 'meta_campaign_id'); }
    public function contact(): BelongsTo { return $this->belongsTo(Contact::class); }
    public function lead(): BelongsTo { return $this->belongsTo(Lead::class); }
}
