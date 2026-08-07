<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SurveyFormResponse extends Model
{
    use HasFactory;

    protected $fillable = [
        'survey_form_id',
        'company_id',
        'contact_id',
        'phone',
        'flow_node_id',
        'answers',
        'status',              // in_progress | completed | abandoned
        'current_field_index',
        'completed_at',
    ];

    protected $casts = [
        // {field_key: answer_value}
        'answers'             => 'array',
        'current_field_index' => 'integer',
        'completed_at'        => 'datetime',
    ];

    public function surveyForm(): BelongsTo
    {
        return $this->belongsTo(SurveyForm::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    // The flow node whose "survey" step triggered this response, if any
    public function flowNode(): BelongsTo
    {
        return $this->belongsTo(FlowNode::class, 'flow_node_id');
    }

    // Convenience: get a single answer by the field's key
    public function answer(string $key): mixed
    {
        return ($this->answers ?? [])[$key] ?? null;
    }

    public function isComplete(): bool
    {
        return $this->status === 'completed';
    }
}
