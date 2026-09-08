<?php

namespace App\Modules\Hr\Support;

use App\Models\CompanyWorkingHour;
use App\Models\StaffAvailability;
use App\Modules\Hr\Models\HrAttendance;
use App\Modules\Hr\Models\HrLeaveRequest;
use App\Modules\Hr\Models\HrSetting;
use App\Modules\Hr\Models\HrStaffProfile;
use Illuminate\Support\Carbon;

/**
 * Attendance business rules: schedule resolution, clock-in status colouring,
 * geofence evaluation and (optional) staff-availability syncing.
 */
class AttendanceService
{
    /**
     * Scheduled start/end datetimes for a staff member on a given date.
     * Precedence: the staff member's own duty hours → the company's per-weekday
     * working hours → the flat hr_settings office hours.
     */
    public function schedule(HrSetting $settings, HrStaffProfile $profile, Carbon $date): array
    {
        $start = $profile->duty_start;
        $end   = $profile->duty_end;

        if (!$start || !$end) {
            $wh = CompanyWorkingHour::where('company_id', $profile->company_id)
                ->where('weekday', (int) $date->dayOfWeek)->first();
            if ($wh && $wh->is_open) {
                $start = $start ?: $wh->start_time;
                $end   = $end ?: $wh->end_time;
            }
        }

        $start = $start ?: $settings->office_start;
        $end   = $end ?: $settings->office_end;

        return [
            'start' => $date->copy()->setTimeFromTimeString((string) $start),
            'end'   => $date->copy()->setTimeFromTimeString((string) $end),
        ];
    }

    /**
     * Colour + lateness for a clock-in relative to the scheduled start.
     *
     *   before (start - earlyWindow)        → info / primary   (very early)
     *   within (start - earlyWindow)…start  → success          (on time)
     *   start … start + grace               → warning          (slightly late)
     *   after  start + grace                → danger           (late)
     *
     * @return array{color: string, late_minutes: int, requires_note: bool}
     */
    public function clockInStatus(Carbon $now, Carbon $start, int $earlyWindow, int $grace): array
    {
        $lateMinutes = max(0, (int) round($start->diffInSeconds($now, false) / 60));

        if ($now->lt($start->copy()->subMinutes($earlyWindow))) {
            $color = 'info';
        } elseif ($now->lt($start)) {
            $color = 'success';
        } elseif ($now->lte($start->copy()->addMinutes($grace))) {
            $color = 'warning';
        } else {
            $color = 'danger';
        }

        return [
            'color'         => $color,
            'late_minutes'  => $lateMinutes,
            'requires_note' => $color === 'danger',
        ];
    }

    /**
     * Evaluate a GPS position against the office geofence. Never blocks — an
     * out-of-range punch is recorded with a danger note (per product rule).
     *
     * @return array{distance: ?int, out_of_geofence: bool, note_color: ?string, note_message: ?string}
     */
    public function geofence(HrSetting $settings, HrStaffProfile $profile, ?float $lat, ?float $lng): array
    {
        $none = ['distance' => null, 'out_of_geofence' => false, 'note_color' => null, 'note_message' => null];

        if (!$profile->isGps() || !$settings->hasGeofence()) {
            return $none;
        }
        if ($lat === null || $lng === null) {
            return ['distance' => null, 'out_of_geofence' => true, 'note_color' => 'danger', 'note_message' => 'No GPS location provided for a GPS-based check-in.'];
        }

        $distance = Geo::meters($lat, $lng, (float) $settings->office_lat, (float) $settings->office_lng);
        $out = $distance > $settings->geofence_radius_m;

        return [
            'distance'        => $distance,
            'out_of_geofence' => $out,
            'note_color'      => $out ? 'danger' : null,
            'note_message'    => $out ? "Punched {$distance} m from the office (allowed: {$settings->geofence_radius_m} m)." : null,
        ];
    }

    /** Whether the staff member has an approved leave covering the date. */
    public function onApprovedLeave(int $companyId, int $userId, Carbon $date): bool
    {
        return HrLeaveRequest::where('company_id', $companyId)
            ->where('user_id', $userId)
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->exists();
    }

    /**
     * Sync staff_availability from attendance state, if the company opted in.
     * $state ∈ working | on_break | off
     */
    public function syncAvailability(HrSetting $settings, int $companyId, int $userId, string $state): void
    {
        if (!$settings->auto_availability) {
            return;
        }

        $av = StaffAvailability::ensureExists($companyId, $userId);

        // staff_availability.status enum = online | away | offline | busy
        match ($state) {
            'working'  => $av->update(['is_online' => true, 'is_available' => true, 'status' => 'online', 'last_seen_at' => now()]),
            'on_break' => $av->update(['is_online' => true, 'is_available' => false, 'status' => 'busy', 'last_seen_at' => now()]),
            default    => $av->update(['is_online' => false, 'is_available' => false, 'status' => 'offline', 'last_seen_at' => now()]),
        };
    }

    /** Recompute break_minutes / worked_minutes on an attendance row. */
    public function recomputeTotals(HrAttendance $att): void
    {
        $breakMinutes = (int) $att->breaks()->whereNotNull('end_at')->sum('minutes');

        $worked = 0;
        if ($att->clock_in_at && $att->clock_out_at) {
            $worked = max(0, (int) round($att->clock_in_at->diffInSeconds($att->clock_out_at) / 60) - $breakMinutes);
        }

        $att->update(['break_minutes' => $breakMinutes, 'worked_minutes' => $worked]);
    }
}
