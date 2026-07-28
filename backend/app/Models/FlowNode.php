<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// ════════════════════════════════════════════════════════════════════════════
// FlowNode
// ════════════════════════════════════════════════════════════════════════════
class FlowNode extends Model
{
    protected $fillable = [
        'company_id', 'parent_id', 'title', 'message', 'type',
        'reply_id', 'lead_category', 'sort_order', 'is_active', 'trigger_count',
    ];

    protected $casts = [
        'is_active'     => 'boolean',
        'sort_order'    => 'integer',
        'trigger_count' => 'integer',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────
    public function company(): BelongsTo  { return $this->belongsTo(Company::class); }
    public function parent(): BelongsTo   { return $this->belongsTo(FlowNode::class, 'parent_id'); }
    public function children(): HasMany   { return $this->hasMany(FlowNode::class, 'parent_id')->orderBy('sort_order'); }
    public function leads(): HasMany      { return $this->hasMany(Lead::class, 'flow_node_id'); }
    public function sessions(): HasMany   { return $this->hasMany(FlowSession::class, 'current_node_id'); }

    // ── Scopes ────────────────────────────────────────────────────────────────
    public function scopeActive($q)   { return $q->where('is_active', true); }
    public function scopeRoots($q)    { return $q->whereNull('parent_id'); }
    public function scopeOrdered($q)  { return $q->orderBy('sort_order'); }

    // ── Helpers ───────────────────────────────────────────────────────────────
    public function isButton(): bool { return $this->type === 'button'; }
    public function isList(): bool   { return $this->type === 'list'; }
    public function isText(): bool   { return $this->type === 'text'; }

    public function maxChildren(): int
    {
        return match ($this->type) {
            'button' => 3,
            'list'   => 10,
            default  => 0,
        };
    }
}
