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
        'leave_request_updates', 'payroll_released', 'attendance_reminders', 'modes',
    ];

    /** The kinds of notification a person can tune — the same four groups that pick a tone (config/notification_tones.php). */
    public const GROUPS = ['lead_popup', 'lead', 'conversation', 'general'];
    /** sound = notification with its tone · silent = notification only, no tone · off = no notification */
    public const MODES = ['sound', 'silent', 'off'];

    /** Keeps only known groups with a known mode. */
    public static function cleanModes(?array $modes): array
    {
        return array_filter((array) $modes, fn ($m, $g) => in_array($g, self::GROUPS, true) && in_array($m, self::MODES, true), ARRAY_FILTER_USE_BOTH);
    }

    /** How a notification of tone-group $group reaches this person: that phone's own setting if it has one, else theirs, else sound. */
    public static function resolveMode(string $group, ?array $account, ?array $device = null): string
    {
        return $device[$group] ?? $account[$group] ?? 'sound';
    }

    protected $casts = [
        'new_lead_alert'         => 'boolean',
        'ai_handoff_offers'      => 'boolean',
        'sla_breach_alert'       => 'boolean',
        'campaign_updates'       => 'boolean',
        'leave_request_updates'  => 'boolean',
        'payroll_released'       => 'boolean',
        'attendance_reminders'   => 'boolean',
        'modes'                  => 'array',
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
