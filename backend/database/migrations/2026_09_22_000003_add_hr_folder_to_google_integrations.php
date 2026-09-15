<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A separate Drive root folder for HR attendance selfies, alongside the
 *  existing leads folder — day-based subfolders are created under this one. */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('google_integrations', function (Blueprint $table) {
            $table->string('drive_hr_folder_id')->nullable()->after('drive_folder_id');
            $table->string('drive_hr_folder_url')->nullable()->after('drive_hr_folder_id');
        });
    }

    public function down(): void
    {
        Schema::table('google_integrations', function (Blueprint $table) {
            $table->dropColumn(['drive_hr_folder_id', 'drive_hr_folder_url']);
        });
    }
};
