<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Late clock-in reason": required (can't clock in late without one), optional (the app asks but staff may skip),
 * or off (never asked). Replaces the old on/off hr_settings.require_late_note, which stays in step with it.
 * The reason itself is stored on hr_attendance.late_note and hr_attendance_sessions.clock_in_note.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('hr_settings', function (Blueprint $table) {
            $table->string('late_note_mode', 10)->default('required')->after('require_late_note');
        });

        // keep every company's current behaviour: the old boolean on → required, off → off
        DB::table('hr_settings')->where('require_late_note', false)->update(['late_note_mode' => 'off']);
    }

    public function down(): void
    {
        Schema::table('hr_settings', function (Blueprint $table) {
            $table->dropColumn('late_note_mode');
        });
    }
};
