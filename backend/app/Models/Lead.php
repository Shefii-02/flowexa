<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

// ════════════════════════════════════════════════════════════════════════════
// Lead
// ════════════════════════════════════════════════════════════════════════════
class Lead extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'company_id', 'contact_id', 'assigned_to', 'assigned_by',
        'flow_node_id', 'campaign_id', 'stage', 'priority', 'category',
        'source', 'notes', 'crm_id', 'followed_up_at', 'enrolled_at', 'assigned_at',
    ];

    protected $casts = [
        'followed_up_at' => 'datetime',
        'enrolled_at'    => 'datetime',
        'assigned_at'    => 'datetime',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────
    public function company(): BelongsTo    { return $this->belongsTo(Company::class); }
    public function contact(): BelongsTo    { return $this->belongsTo(Contact::class); }
    public function assignedTo(): BelongsTo { return $this->belongsTo(User::class, 'assigned_to'); }
    public function assignedBy(): BelongsTo { return $this->belongsTo(User::class, 'assigned_by'); }
    public function flowNode(): BelongsTo   { return $this->belongsTo(FlowNode::class, 'flow_node_id'); }
    public function campaign(): BelongsTo   { return $this->belongsTo(Campaign::class); }
    public function events(): HasMany       { return $this->hasMany(LeadEvent::class)->latest(); }

    // ── Scopes ────────────────────────────────────────────────────────────────
    public function scopeActive($q)     { return $q->whereNotIn('stage', ['enrolled', 'lost']); }
    public function scopeEnrolled($q)   { return $q->where('stage', 'enrolled'); }
    public function scopeUnassigned($q) { return $q->whereNull('assigned_to'); }
    public function scopeForUser($q, int $userId) { return $q->where('assigned_to', $userId); }

    // ── Helpers ───────────────────────────────────────────────────────────────
    public function isActive(): bool   { return !in_array($this->stage, ['enrolled', 'lost']); }
    public function isEnrolled(): bool { return $this->stage === 'enrolled'; }
    public function isLost(): bool     { return $this->stage === 'lost'; }
}


