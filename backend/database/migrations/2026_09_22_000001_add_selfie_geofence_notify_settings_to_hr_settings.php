<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Feature-by-feature attendance toggles: selfie requirement per punch type,
 * whether the office geofence is a hard block for WFO staff (vs. recorded
 * only), a configurable before/after window around the scheduled clock-out
 * (mirroring the existing clock-in early/grace window), and who gets notified
 * for late clock-ins, late/early clock-outs, break overruns and new leave
 * requests.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('hr_settings', function (Blueprint $table) {
            $table->boolean('geofence_mandatory')->default(false)->after('geofence_radius_m');

            $table->boolean('selfie_required_clock_in')->default(true)->after('require_early_leave_note');
            $table->boolean('selfie_required_clock_out')->default(true)->after('selfie_required_clock_in');
            $table->boolean('selfie_required_break_start')->default(false)->after('selfie_required_clock_out');
            $table->boolean('selfie_required_break_end')->default(false)->after('selfie_required_break_start');

            $table->unsignedSmallInteger('clock_out_early_window_minutes')->default(15)->after('grace_minutes');
            $table->unsignedSmallInteger('clock_out_grace_minutes')->default(15)->after('clock_out_early_window_minutes');

            $table->json('late_clockin_notify_user_ids')->nullable()->after('selfie_required_break_end');
            $table->json('late_clockout_notify_user_ids')->nullable()->after('late_clockin_notify_user_ids');
            $table->json('break_overrun_notify_user_ids')->nullable()->after('late_clockout_notify_user_ids');
            $table->json('leave_request_notify_user_ids')->nullable()->after('break_overrun_notify_user_ids');
        });
    }

    public function down(): void
    {
        Schema::table('hr_settings', function (Blueprint $table) {
            $table->dropColumn([
                'geofence_mandatory',
                'selfie_required_clock_in',
                'selfie_required_clock_out',
                'selfie_required_break_start',
                'selfie_required_break_end',
                'clock_out_early_window_minutes',
                'clock_out_grace_minutes',
                'late_clockin_notify_user_ids',
                'late_clockout_notify_user_ids',
                'break_overrun_notify_user_ids',
                'leave_request_notify_user_ids',
            ]);
        });
    }
};
