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

    // Flow nodes of type 'survey' that use this form
    public function flowNodes(): HasMany
    {
        return $this->hasMany(FlowNode::class, 'survey_form_id');
    }

    // Convenience: look up a single field definition by its key
    public function field(string $key): ?array
    {
        return collect($this->fields ?? [])->firstWhere('key', $key);
    }
}
