<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SurveyForm extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'name',
        'description',
        'fields',
        'is_active',
        'flow_id',      // Meta's Flow ID once published as a native WhatsApp Flow
        'flow_status',  // draft | published | deprecated — null until first publish
    ];

    protected $casts = [
        // Ordered list of questions: [{key, question_text, type, options?, required}]
        'fields'    => 'array',
        'is_active' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function responses(): HasMany
    {
        return $this->hasMany(SurveyFormResponse::class);
    }

    public function flowNodes(): HasMany
    {
        return $this->hasMany(FlowNode::class, 'survey_form_id');
    }

    public function field(string $key): ?array
    {
        return collect($this->fields ?? [])->firstWhere('key', $key);
    }

    // Whether this form is available to send as a native bottom-sheet Flow.
    // Falls back to the sequential text-message survey when false.
    public function isNativeFlowReady(): bool
    {
        return !empty($this->flow_id) && $this->flow_status === 'published';
    }
}
