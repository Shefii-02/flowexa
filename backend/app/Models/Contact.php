<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;


// ════════════════════════════════════════════════════════════════════════════
// Contact
// ════════════════════════════════════════════════════════════════════════════
class Contact extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'company_id', 'phone', 'name', 'email', 'wa_id',
        'custom_fields', 'opted_in', 'opted_out_at',
        'last_message_at', 'crm_id',
        // AI / lead intelligence fields
        'lead_score', 'lead_score_updated_at', 'lead_stage',
        'last_sentiment', 'detected_intent',
        'buying_signals_count', 'objections_count',
        'meta_ai_profile', 'conversation_summary', 'summary_updated_at',
    ];

    protected $casts = [
        'custom_fields'        => 'array',
        'opted_in'             => 'boolean',
        'opted_out_at'         => 'datetime',
        'last_message_at'      => 'datetime',
        'lead_score_updated_at'=> 'datetime',
        'summary_updated_at'   => 'datetime',
        'meta_ai_profile'      => 'array',
        'lead_score'           => 'integer',
        'buying_signals_count' => 'integer',
        'objections_count'     => 'integer',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────
    public function company(): BelongsTo  { return $this->belongsTo(Company::class); }
    public function leads(): HasMany      { return $this->hasMany(Lead::class); }
    public function messages(): HasMany   { return $this->hasMany(MessageLog::class); }

    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(ContactLabel::class, 'contact_label_pivot', 'contact_id', 'contact_label_id');
    }

    // ── Scopes ────────────────────────────────────────────────────────────────
    public function scopeOptedIn($q)   { return $q->where('opted_in', true); }
    public function scopeOptedOut($q)  { return $q->where('opted_in', false); }
    public function scopeForCompany($q, int $id) { return $q->where('company_id', $id); }
}
