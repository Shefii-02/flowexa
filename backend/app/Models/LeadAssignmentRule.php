<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadAssignmentRule extends Model
{
    protected $fillable = [
        'company_id', 'auto_assign_enabled',
        'weight_availability', 'weight_max_leads', 'weight_performance', 'weight_workload',
        'sla_minutes', 'ai_takeover_after_minutes',
        'notification_mode', 'notification_gap_seconds', 'notification_timeout_seconds', 'max_notification_rounds',
        'duplicate_window_days', 'duplicate_action',
        'working_hours_start', 'working_hours_end', 'working_days', 'timezone',
    ];

    protected $casts = [
        'auto_assign_enabled' => 'boolean',
        'working_days'        => 'array',
    ];

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }

    public static function defaultForCompany(int $companyId): array
    {
        return [
            'company_id'                   => $companyId,
            'auto_assign_enabled'          => true,
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
