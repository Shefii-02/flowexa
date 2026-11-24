<?php

namespace App\Services;

use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Mobile push (FCM) to ONE staff member for lead events — the app-side twin of the web socket popups. Honours the person's own
 * notification switches (Settings → Notifications) and never lets a push failure break lead routing.
 */
class StaffPush
{
    /** @param string $pref one of NotificationPreference::TYPES; @param array<string,scalar|null> $data must carry an `action` the app understands */
    public static function toStaff(int $staffId, string $pref, string $type, string $title, string $body, array $data): void
    {
        try {
            $user = User::find($staffId);
            if (!$user || !$user->is_active) { return; }
            if (in_array($pref, NotificationPreference::TYPES, true)
                && NotificationPreference::forUser((int) $user->company_id, $staffId)->{$pref} === false) {
                return;   // they switched this kind of alert off
            }
            // FCM data values must be strings
            $data = array_map(fn ($v) => (string) ($v ?? ''), $data);
            app(FirebasePushService::class)->sendToUser($staffId, $type, $title, $body, $data);
        } catch (\Throwable $e) {
            Log::warning('StaffPush failed: ' . $e->getMessage(), ['staff' => $staffId, 'type' => $type]);
        }
    }
}
