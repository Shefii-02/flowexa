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
     * @param array|null  $days     legacy fallback — weekdays a single shared range applies to,
     *                              0=Sunday..6=Saturday. Only used when $hours has no entry at all.
     * @param string|null $start    legacy fallback — "HH:MM:SS" or "HH:MM", shared across $days
     * @param string|null $end      legacy fallback — "HH:MM:SS" or "HH:MM", shared across $days
     * @param string|null $timezone defaults to Asia/Kolkata when not set
     * @param array|null  $hours    per-day hours: { "0": {"start":"09:00","end":"18:00"}, "1": {...}, ... },
     *                              keyed by weekday 0=Sunday..6=Saturday. A day missing from this
     *                              map means the AI is off that day. Takes priority over
     *                              $days/$start/$end whenever it's set at all (even to `[]`, i.e.
     *                              every day turned off) — the legacy fields are only consulted
     *                              when a row hasn't been saved under the new per-day model yet.
     */
    public static function isActiveNow(
        string  $mode,
        ?array  $days,
        ?string $start,
        ?string $end,
        ?string $timezone,
        ?array  $hours = null,
    ): bool {
        if ($mode !== 'scheduled') {
            return true; // 'always' (or any unrecognized mode) — never restricted
        }

        $tz  = $timezone ?: 'Asia/Kolkata';
        $now = Carbon::now($tz);

        if ($hours !== null) {
            $today = $hours[(string) $now->dayOfWeek] ?? $hours[$now->dayOfWeek] ?? null;
            if (!$today || empty($today['start']) || empty($today['end'])) {
                return false; // today isn't in the map at all — AI is off today
            }
            $startAt = $now->copy()->setTimeFromTimeString($today['start']);
            $endAt   = $now->copy()->setTimeFromTimeString($today['end']);
            return $now->betweenIncluded($startAt, $endAt);
        }

        // Legacy shape: one shared range applied to a set of days.
        // A "scheduled" mode with no actual hours configured yet shouldn't silently block
        // every message — treat it as not-yet-configured rather than always-closed.
        if (!$start || !$end) {
            return true;
        }

        $activeDays = $days ?: [0, 1, 2, 3, 4, 5, 6];
        if (!in_array((int) $now->dayOfWeek, $activeDays, true)) {
            return false;
        }

        $startAt = $now->copy()->setTimeFromTimeString($start);
        $endAt   = $now->copy()->setTimeFromTimeString($end);

        return $now->betweenIncluded($startAt, $endAt);
    }
}
