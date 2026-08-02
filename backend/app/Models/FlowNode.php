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
        'company_id',
        'flow_builder_id',
        'parent_id',
        'title',
        'message',
        'multi_messages',
        'type',
        'reply_id',
        'lead_category',
        'sort_order',
        'is_active',
        'media_type',
        'media_url',
        'media_id',
        'media_caption',
        'media_filename',
        'location_lat',
        'location_lng',
        'location_name',
        'location_address',
        'dynamic_api_url',
        'dynamic_api_method',
        'dynamic_api_headers',
        'dynamic_label_field',
        'dynamic_value_field',
        'dynamic_description_field',
        'dynamic_image_field',
        'dynamic_subtitle_field',
        'trigger_count',
    ];

    // protected $fillable = [
    //     'company_id',
    //     'parent_id',
    //     'title',
    //     'message',
    //     'type',
    //     'reply_id',
    //     'lead_category',
    //     'sort_order',
    //     'is_active',
    //     'trigger_count',
    // ];

    protected $casts = [
        'is_active'     => 'boolean',
        'sort_order'    => 'integer',
        'trigger_count' => 'integer',
        'multi_messages' => 'array',
        'location_lat'   => 'float',
        'location_lng'   => 'float',
    ];


    // ── Relationships ─────────────────────────────────────────────────────────
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
    public function parent(): BelongsTo
    {
        return $this->belongsTo(FlowNode::class, 'parent_id');
    }
    public function children(): HasMany
    {
        return $this->hasMany(FlowNode::class, 'parent_id')->orderBy('sort_order');
    }
    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class, 'flow_node_id');
    }
    public function sessions(): HasMany
    {
        return $this->hasMany(FlowSession::class, 'current_node_id');
    }

    // ── Scopes ────────────────────────────────────────────────────────────────
    public function scopeActive($q)
    {
        return $q->where('is_active', true);
    }
    public function scopeRoots($q)
    {
        return $q->whereNull('parent_id');
    }
    public function scopeOrdered($q)
    {
        return $q->orderBy('sort_order');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────
    public function isButton(): bool
    {
        return $this->type === 'button';
    }
    public function isList(): bool
    {
        return $this->type === 'list';
    }
    public function isText(): bool
    {
        return $this->type === 'text';
    }

    public function maxChildren(): int
    {
        return match ($this->type) {
            'button' => 3,
            'list'   => 10,
            default  => 0,
        };
    }

    public function builder()
    {
        return $this->belongsTo(FlowBuilder::class, 'flow_builder_id');
    }

    public function hasMultipleMessages(): bool
    {
        return !empty($this->multi_messages) && count($this->multi_messages) > 0;
    }


    public function mediaAssets()
    {
        return $this->hasMany(MediaAsset::class);
    }
}
