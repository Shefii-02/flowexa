<?php

namespace App\Modules\WaChat\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AutomationRule extends Model
{
    protected $fillable = [
        'company_id', 'session_id', 'rule_type', 'name',
        'conditions', 'actions', 'keywords', 'priority',
        'is_active', 'schedule_start', 'schedule_end', 'schedule_days',
        'delay_hours', 'inactivity_hours',
    ];

    protected $casts = [
        'conditions'     => 'array',
        'actions'        => 'array',
        'keywords'       => 'array',
        'schedule_days'  => 'array',
        'is_active'      => 'boolean',
    ];

    public function logs(): HasMany
    {
        return $this->hasMany(AutomationLog::class, 'rule_id');
    }
}
