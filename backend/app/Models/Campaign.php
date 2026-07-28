<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

// ════════════════════════════════════════════════════════════════════════════
// Campaign
// ════════════════════════════════════════════════════════════════════════════
class Campaign extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'company_id', 'created_by', 'template_id', 'name', 'description',
        'template_variables', 'target_type', 'target_labels', 'csv_file',
        'throttle_per_minute', 'status', 'total_contacts', 'sent',
        'delivered', 'read', 'failed', 'pending', 'wallet_debited',
        'scheduled_at', 'started_at', 'completed_at',
    ];

    protected $casts = [
        'template_variables' => 'array',
        'target_labels'      => 'array',
        'scheduled_at'       => 'datetime',
        'started_at'         => 'datetime',
        'completed_at'       => 'datetime',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────
    public function company(): BelongsTo    { return $this->belongsTo(Company::class); }
    public function creator(): BelongsTo    { return $this->belongsTo(User::class, 'created_by'); }
    public function template(): BelongsTo   { return $this->belongsTo(WaTemplate::class, 'template_id'); }
    public function contacts(): HasMany     { return $this->hasMany(CampaignContact::class); }

    // ── Scopes ────────────────────────────────────────────────────────────────
    public function scopeRunning($q)   { return $q->where('status', 'running'); }
    public function scopeCompleted($q) { return $q->where('status', 'completed'); }
    public function scopeDraft($q)     { return $q->where('status', 'draft'); }

    // ── Helpers ───────────────────────────────────────────────────────────────
    public function isRunning(): bool   { return $this->status === 'running'; }
    public function isPaused(): bool    { return $this->status === 'paused'; }
    public function isDraft(): bool     { return $this->status === 'draft'; }
    public function isCompleted(): bool { return $this->status === 'completed'; }

    public function getDeliveryRateAttribute(): float
    {
        return $this->total_contacts > 0
            ? round(($this->delivered / $this->total_contacts) * 100, 1)
            : 0.0;
    }

    public function getReadRateAttribute(): float
    {
        return $this->total_contacts > 0
            ? round(($this->read / $this->total_contacts) * 100, 1)
            : 0.0;
    }
}

