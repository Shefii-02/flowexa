<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserNotification extends Model
{
    protected $fillable = ['company_id', 'user_id', 'category', 'type', 'title', 'body', 'data', 'read_at'];
    protected $casts = ['data' => 'array', 'read_at' => 'datetime'];

    /** type → the bell's tab: leads vs everything else */
    public static function categoryFor(string $type): string
    {
        return match (true) {
            str_starts_with($type, 'lead') || in_array($type, ['new_lead', 'ai_handoff_offer', 'sla_breach', 'live_chat'], true) => 'lead',
            str_starts_with($type, 'team_chat') => 'team',
            str_contains($type, 'leave') || str_contains($type, 'payroll') || str_contains($type, 'attendance') => 'hr',
            str_starts_with($type, 'campaign') => 'campaign',
            $type === 'low_balance' => 'billing',
            str_starts_with($type, 'wa_') => 'system',
            default => 'other',
        };
    }
}
