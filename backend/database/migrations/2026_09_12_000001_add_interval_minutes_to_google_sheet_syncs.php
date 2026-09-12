<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('google_sheet_syncs', function (Blueprint $table) {
            // Minute-level cadence (e.g. every 10 minutes) alongside the older interval_hours.
            // When set, interval_minutes takes precedence — see GoogleSheetSync::isDue().
            $table->unsignedInteger('interval_minutes')->nullable()->after('interval_hours');
        });
    }

    public function down(): void
    {
        Schema::table('google_sheet_syncs', function (Blueprint $table) {
            $table->dropColumn('interval_minutes');
        });
    }
};
