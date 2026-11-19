<?php

namespace App\Services\LeadAssignment;

use App\Modules\Hr\Models\HrAttendance;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Turns a "who may receive leads" choice into the list of user ids the engine may pick from.
 *
 *   all              every eligible employee                         → null (no restriction)
 *   online           everyone clocked in right now (HR attendance)   → their ids
 *   selected         the chosen employees                            → those ids
 *   selected_online  the chosen employees who are clocked in         → the overlap
 *
 * "Clocked in" = today's attendance row has a clock-in, no clock-out yet, and no break in progress.
 * An empty result is returned as [NOBODY], never as [] — an empty pool means "no restriction" to the engine.
 */
class StaffPoolResolver
{
    public const SCOPES = ['all', 'online', 'selected', 'selected_online'];
    public const NOBODY = -1;

    /** Scopes that depend on HR attendance. */
    public const ATTENDANCE_SCOPES = ['online', 'selected_online'];

    /** Is the HR section (attendance) available to this company — in its plan, and installed? */
    public function attendanceAvailable(int $companyId): bool
    {
        // asked up to three times per assigned lead — remember the answer for a minute (a plan change shows up on the next minute)
        $hit = self::$hrMemo[$companyId] ?? null;
        if ($hit && time() - $hit[0] < 60) {
            return $hit[1];
        }
        $ok = false;
        if (Schema::hasTable('hr_attendance')) {
            $company = \App\Models\Company::find($companyId);
            $ok = $company ? \App\Support\Entitlements::has($company, 'hr') : false;
        }
        self::$hrMemo[$companyId] = [time(), $ok];
        return $ok;
    }

    /** @var array<int,array{0:int,1:bool}> */
    private static array $hrMemo = [];

    public static function forgetMemo(): void { self::$hrMemo = []; }

    /** True when this scope relies on attendance but HR is not available — the engine then falls back to round robin over everyone. */
    public function fallsBackToEveryone(int $companyId, ?string $scope): bool
    {
        return in_array($scope, self::ATTENDANCE_SCOPES, true) && !$this->attendanceAvailable($companyId);
    }

    /** @return int[]|null null = any eligible employee */
    public function resolve(int $companyId, ?string $scope, ?array $staffIds): ?array
    {
        $scope = in_array($scope, self::SCOPES, true) ? $scope : 'all';
        if ($this->fallsBackToEveryone($companyId, $scope)) {
            // HR is not available, so nobody can be "online": attendance-based choices become "all staff" / "the selected staff"
            $scope = $scope === 'online' ? 'all' : 'selected';
        }
        $selected = array_values(array_unique(array_map('intval', $staffIds ?? [])));

        $pool = match ($scope) {
            'all'             => null,
            'online'          => $this->onlineIds($companyId),
            'selected'        => $selected,
            'selected_online' => array_values(array_intersect($selected, $this->onlineIds($companyId))),
        };

        if ($pool === null) {
            return null;
        }
        return $pool ?: [self::NOBODY];
    }

    /** Employees who are on the clock right now. @return int[] */
    public function onlineIds(int $companyId): array
    {
        if (!Schema::hasTable('hr_attendance')) {
            return [];
        }
        $q = DB::table('hr_attendance')
            ->where('company_id', $companyId)
            ->whereDate('work_date', now()->toDateString())
            ->whereNotNull('clock_in_at')
            ->whereNull('clock_out_at');

        if (Schema::hasTable('hr_break_sessions')) {
            $q->whereNotExists(fn ($b) => $b->select(DB::raw(1))->from('hr_break_sessions')
                ->whereColumn('hr_break_sessions.attendance_id', 'hr_attendance.id')->whereNull('hr_break_sessions.end_at'));
        }

        return $q->pluck('user_id')->map(fn ($i) => (int) $i)->all();
    }
}
