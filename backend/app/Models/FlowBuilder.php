<?php

namespace App\Models;

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
        'total_leads'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'trigger_keywords'=> 'array',
        'active_from' => 'datetime',
        'active_until' => 'datetime',
         'total_sessions'   => 'integer',
        'total_leads'      => 'integer',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function nodes(): HasMany
    {
        return $this->hasMany(FlowNode::class, 'flow_builder_id');
    }

}
