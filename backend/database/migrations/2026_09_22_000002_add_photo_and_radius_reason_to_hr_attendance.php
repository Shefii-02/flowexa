<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Selfie photo URLs (clock in/out, break start/end) and the staff member's own
 *  reason when a punch lands outside the office geofence. */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('hr_attendance', function (Blueprint $table) {
            $table->string('clock_in_photo_url', 500)->nullable()->after('clock_in_out_of_geofence');
            $table->string('clock_out_photo_url', 500)->nullable()->after('clock_in_photo_url');
            $table->text('clock_in_radius_reason')->nullable()->after('clock_in_photo_url');
            $table->text('clock_out_radius_reason')->nullable()->after('clock_out_photo_url');
        });

        Schema::table('hr_break_sessions', function (Blueprint $table) {
            $table->string('start_photo_url', 500)->nullable()->after('end_distance_m');
            $table->string('end_photo_url', 500)->nullable()->after('start_photo_url');
        });
    }

    public function down(): void
    {
        Schema::table('hr_attendance', function (Blueprint $table) {
            $table->dropColumn([
                'clock_in_photo_url', 'clock_out_photo_url',
                'clock_in_radius_reason', 'clock_out_radius_reason',
            ]);
        });
        Schema::table('hr_break_sessions', function (Blueprint $table) {
            $table->dropColumn(['start_photo_url', 'end_photo_url']);
        });
    }
};
