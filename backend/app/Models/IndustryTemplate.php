<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A per-industry catalog preset (real estate, health clinic, software company…) — what the
 * Catalog listing schema looks like, what qualifies a lead, and the vertical-specific
 * guidance appended to the agent prompt. Read through App\Modules\Catalog\IndustryTemplates
 * rather than directly, so callers stay decoupled from where this data actually lives.
 */
class IndustryTemplate extends Model
{
    protected $fillable = [
        'key', 'name', 'listing_type', 'attribute_schema', 'qualification_fields',
        'question_flow', 'agent_prompt', 'lead_source', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'attribute_schema'     => 'array',
        'qualification_fields' => 'array',
        'question_flow'        => 'array',
        'is_active'            => 'boolean',
        'sort_order'           => 'integer',
    ];

    /** The shape App\Modules\Catalog\IndustryTemplates::get() has always returned. */
    public function toLegacyArray(): array
    {
        return [
            'name'                  => $this->name,
            'listing_type'          => $this->listing_type,
            'attribute_schema'      => $this->attribute_schema ?? [],
            'qualification_fields'  => $this->qualification_fields ?? [],
            'question_flow'         => $this->question_flow ?? [],
            'agent_prompt'          => $this->agent_prompt ?? '',
            'lead_source'           => $this->lead_source,
        ];
    }
}
