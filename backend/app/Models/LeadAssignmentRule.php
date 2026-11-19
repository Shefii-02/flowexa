<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadAssignmentRule extends Model
{
    protected $fillable = [
        'company_id', 'auto_assign_enabled', 'strategy',
        'weight_availability', 'weight_max_leads', 'weight_performance', 'weight_workload',
        'sla_minutes', 'ai_takeover_after_minutes',
        'notification_mode', 'notification_gap_seconds', 'notification_timeout_seconds', 'max_notification_rounds',
        'duplicate_window_days', 'duplicate_action',
        'working_hours_start', 'working_hours_end', 'working_days', 'timezone',
        'auto_assign_run_mode', 'auto_assign_interval_minutes', 'auto_assign_run_start', 'auto_assign_run_end',
        'auto_assign_last_run_at', 'auto_assign_last_run_count',
    ];

    protected $casts = [
        'auto_assign_enabled' => 'boolean',
        'working_days'        => 'array',
        'auto_assign_last_run_at' => 'datetime',
    ];

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }

    /** Is "Auto-assign new leads" on for this company? (no saved rule yet = the default, on) */
    public static function autoEnabled(int $companyId): bool
    {
        $rule = static::where('company_id', $companyId)->first();
        return $rule ? (bool) $rule->auto_assign_enabled : true;
    }

    /** Should a new lead be routed the moment it arrives? (auto-assign on AND run mode "instant"; "scheduled" leaves it for the cron) */
    public static function routesInstantly(int $companyId): bool
    {
        $rule = static::where('company_id', $companyId)->first();
        return $rule ? ($rule->auto_assign_enabled && $rule->auto_assign_run_mode !== 'scheduled') : true;
    }

    public static function defaultForCompany(int $companyId): array
    {
        return [
            'company_id'                   => $companyId,
            'auto_assign_enabled'          => true,
            'strategy'                     => 'round_robin',
            'weight_availability'          => 30,
            'weight_max_leads'             => 25,
            'weight_performance'           => 25,
            'weight_workload'              => 20,
            'sla_minutes'                  => 30,
            'ai_takeover_after_minutes'    => 30,
            'notification_mode'            => 'hybrid',
            'notification_gap_seconds'     => 30,
            'notification_timeout_seconds' => 60,
            'max_notification_rounds'      => 3,
            'duplicate_window_days'        => 90,
            'duplicate_action'             => 'assign_same_staff',
            'working_hours_start'          => '09:00:00',
            'working_hours_end'            => '18:00:00',
            'working_days'                 => [1, 2, 3, 4, 5],
            'timezone'                     => 'Asia/Kolkata',
        ];
    }
}
