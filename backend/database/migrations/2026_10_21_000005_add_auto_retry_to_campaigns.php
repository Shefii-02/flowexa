<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            // false = never auto-retry this campaign's marketing-capped (131049 / 130472) failures; manual retry still works
            $table->boolean('auto_retry')->default(true)->after('daily_limit');
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn('auto_retry');
        });
    }
};
