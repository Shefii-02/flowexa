<?php

namespace App\Modules\WaCloud\Models;

use App\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @see database/migrations/2026_09_08_000006_create_wa_cloud_automation_rules_table.php
 */
class WaCloudAutomationRule extends Model
{
    protected $table = 'wa_cloud_automation_rules';

    public const RULE_TYPES = [
        'welcome_message',
        'out_of_office',
        'lead_qualifier',
        'follow_up_reminder',
        'follow_up_agent',
        'keyword_trigger',
        'inactivity_trigger',
    ];

    protected $fillable = [
        'company_id', 'wa_phone_number_id', 'rule_type', 'name',
        'conditions', 'actions', 'keywords', 'priority', 'is_active',
        'schedule_start', 'schedule_end', 'schedule_days',
        'delay_hours', 'inactivity_hours',
    ];

    protected $casts = [
        'conditions'    => 'array',
        'actions'       => 'array',
        'keywords'      => 'array',
        'schedule_days' => 'array',
        'is_active'     => 'boolean',
        'priority'      => 'integer',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(WaCloudAutomationLog::class, 'rule_id');
    }
}
