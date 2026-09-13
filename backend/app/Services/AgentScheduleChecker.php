<?php

namespace App\Services;

use Illuminate\Support\Carbon;

/**
 * Shared "is the AI agent allowed to answer right now?" check — used identically by
 * AgentPlaybook (covers both WA Chat sessions and WA Cloud numbers, since a playbook is
 * scoped per session_id) and InstagramAccount, so all three channels the AI agent runs on
 * share one schedule concept instead of three subtly-different reimplementations.
 */
class AgentScheduleChecker
{
    /**
     * @param string      $mode     'always' or 'scheduled'
     * @param array|null  $days     weekdays the schedule applies on, 0=Sunday..6=Saturday
     * @param string|null $start    "HH:MM:SS" or "HH:MM"
     * @param string|null $end      "HH:MM:SS" or "HH:MM"
     * @param string|null $timezone defaults to Asia/Kolkata when not set
     */
    public static function isActiveNow(
        string  $mode,
        ?array  $days,
        ?string $start,
        ?string $end,
        ?string $timezone
    ): bool {
        if ($mode !== 'scheduled') {
            return true; // 'always' (or any unrecognized mode) — never restricted
        }

        // A "scheduled" mode with no actual hours configured yet shouldn't silently block
        // every message — treat it as not-yet-configured rather than always-closed.
        if (!$start || !$end) {
            return true;
        }

        $tz  = $timezone ?: 'Asia/Kolkata';
        $now = Carbon::now($tz);

        $activeDays = $days ?: [0, 1, 2, 3, 4, 5, 6];
        if (!in_array((int) $now->dayOfWeek, $activeDays, true)) {
            return false;
        }

        $startAt = $now->copy()->setTimeFromTimeString($start);
        $endAt   = $now->copy()->setTimeFromTimeString($end);

        return $now->betweenIncluded($startAt, $endAt);
    }
}
