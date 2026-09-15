<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Tracks HR attendance-selfie storage separately from the WA media usage
 *  already tracked via waha_media_used_mb/limit_mb — most companies connect
 *  Google Drive (which doesn't count against this), but local fallback
 *  storage still needs its own capacity shown in the Media Library sidebar. */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (!Schema::hasColumn('companies', 'hr_storage_limit_mb')) {
                $table->integer('hr_storage_limit_mb')->default(500)->after('waha_media_used_mb');
            }
            if (!Schema::hasColumn('companies', 'hr_storage_used_mb')) {
                $table->decimal('hr_storage_used_mb', 10, 2)->default(0)->after('hr_storage_limit_mb');
            }
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['hr_storage_limit_mb', 'hr_storage_used_mb']);
        });
    }
};
