<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * companies.max_web_devices_per_user : how many browsers (web sessions) one user may be
 * signed in on at once. The mobile limit stays in companies.max_devices_per_user.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (!Schema::hasColumn('companies', 'max_web_devices_per_user')) {
                $table->unsignedTinyInteger('max_web_devices_per_user')->default(3);
            }
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (Schema::hasColumn('companies', 'max_web_devices_per_user')) {
                $table->dropColumn('max_web_devices_per_user');
            }
        });
    }
};
