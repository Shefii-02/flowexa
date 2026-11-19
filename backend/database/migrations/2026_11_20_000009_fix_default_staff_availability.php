<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A staff_availability row used to start as "offline" and only the HR attendance sync (or a manual toggle) ever made it
 * "online" — so a company that does not run availability from attendance had NOBODY available, and auto-assign (new leads and
 * the Auto assign button alike) found no staff. Rows nobody ever touched are brought in line with the new default:
 *
 *   company uses HR attendance to drive availability  → stay offline until they clock in (unchanged)
 *   every other company                               → available (online)
 *
 * "Untouched" = offline, available flag still on, never seen (last_seen_at null) — i.e. created by the old default.
 */
return new class extends Migration {
    public function up(): void
    {
        $hrDriven = [];
        if (Schema::hasTable('hr_settings') && Schema::hasTable('hr_attendance')) {
            $hrDriven = DB::table('hr_settings')->where('auto_availability', true)
                ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('hr_attendance')->whereColumn('hr_attendance.company_id', 'hr_settings.company_id'))
                ->pluck('company_id')->all();
        }

        DB::table('staff_availability')
            ->where('status', 'offline')->where('is_available', true)->whereNull('last_seen_at')
            ->when($hrDriven, fn ($q) => $q->whereNotIn('company_id', $hrDriven))
            ->update(['status' => 'online', 'updated_at' => now()]);
    }

    public function down(): void {}
};
