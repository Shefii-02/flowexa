<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Attendance storage settings. (1) Where attendance selfies are stored is now a setting in the HR attendance config (Office Details → Selfie verification):
 *   media_library  – real folders in the Media Library: HR Attendance → one folder per day
 *   google_drive   – the company's connected Google Drive: HR Attendance → one folder per day
 * Until now it was implicit ("Drive if connected, else the library"). Companies that were already sending photos to Drive keep Drive;
 * everyone else — and every new company — starts on the Media Library.
 * (2) Optionally every punch is also appended as a row to a Google Sheet.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('hr_settings', function (Blueprint $t) {
            $t->string('selfie_storage', 20)->default('media_library')->after('selfie_required_break_end');
            // every punch is also appended as a row to a Google Sheet (created on first use; the id is kept if it is switched off and on again)
            $t->boolean('attendance_sheet_enabled')->default(false)->after('selfie_storage');
            $t->string('attendance_sheet_id', 100)->nullable()->after('attendance_sheet_enabled');
            $t->string('attendance_sheet_url', 300)->nullable()->after('attendance_sheet_id');
        });

        $onDrive = DB::table('google_integrations')->where('is_active', true)->pluck('company_id');
        foreach ($onDrive as $companyId) {
            $plan = DB::table('companies')->join('plans', 'plans.id', '=', 'companies.plan_id')->where('companies.id', $companyId)->value('plans.google_drive_enabled');
            // no plan row = no plan restriction (the old behaviour used Drive in that case too)
            $hasPlan = DB::table('companies')->where('id', $companyId)->whereNotNull('plan_id')->exists();
            if (!$hasPlan || $plan) {
                DB::table('hr_settings')->where('company_id', $companyId)->update(['selfie_storage' => 'google_drive']);
            }
        }
    }

    public function down(): void
    {
        Schema::table('hr_settings', fn (Blueprint $t) => $t->dropColumn(['selfie_storage', 'attendance_sheet_enabled', 'attendance_sheet_id', 'attendance_sheet_url']));
    }
};
