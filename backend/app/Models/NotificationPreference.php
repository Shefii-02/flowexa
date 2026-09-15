<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-user notification toggles (CRM + HRM event types) — self-managed from the mobile app's
 * and web app's Settings screen. One row per user, created on first read with every type
 * defaulted on.
 */
class NotificationPreference extends Model
{
    protected $table = 'notification_preferences';

    public const TYPES = [
        'new_lead_alert', 'ai_handoff_offers', 'sla_breach_alert', 'campaign_updates',
        'leave_request_updates', 'payroll_released', 'attendance_reminders',
    ];

    protected $fillable = [
        'company_id', 'user_id',
        'new_lead_alert', 'ai_handoff_offers', 'sla_breach_alert', 'campaign_updates',
        'leave_request_updates', 'payroll_released', 'attendance_reminders',
    ];

    protected $casts = [
        'new_lead_alert'         => 'boolean',
        'ai_handoff_offers'      => 'boolean',
        'sla_breach_alert'       => 'boolean',
        'campaign_updates'       => 'boolean',
        'leave_request_updates'  => 'boolean',
        'payroll_released'       => 'boolean',
        'attendance_reminders'   => 'boolean',
    ];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }

    public static function forUser(int $companyId, int $userId): self
    {
        return static::firstOrCreate(
            ['user_id' => $userId],
            ['company_id' => $companyId],
        );
    }
}
