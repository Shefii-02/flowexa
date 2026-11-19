<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;


class FlowBuilder extends Model
{

    protected $fillable = [
        'company_id',
        'created_by',
        'name',
        'description',
        'is_active',
        'trigger_type',
        'trigger_keywords',
        'active_from',
        'active_until',
        'total_sessions',
        'total_leads',
        'settings',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'active_from' => 'datetime',
        'active_until' => 'datetime',
         'total_sessions'   => 'integer',
        'total_leads'      => 'integer',
        'settings'         => 'array',
    ];

    /**
     * Always a list of strings. Rows saved by the old importer were JSON-encoded twice (the column came back as a
     * string, which crashed the flow-builders page and silently broke keyword matching) — decode those too.
     */
    protected function triggerKeywords(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                for ($i = 0; $i < 3 && is_string($value); $i++) {
                    $value = json_decode($value, true);
                }
                return is_array($value) ? array_values(array_filter(array_map(fn ($k) => is_scalar($k) ? trim((string) $k) : '', $value), 'strlen')) : [];
            },
            set: function ($value) {
                if (is_string($value)) {
                    $decoded = json_decode($value, true);
                    $value = is_array($decoded) ? $decoded : ($value === '' ? [] : [$value]);
                }
                return json_encode(array_values((array) ($value ?? [])), JSON_UNESCAPED_UNICODE);
            },
        );
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function nodes(): HasMany
    {
        return $this->hasMany(FlowNode::class, 'flow_builder_id');
    }

}
