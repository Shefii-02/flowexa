<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            // Max messages sent in any rolling 24h (null = no limit). Spreads a big list over several days.
            $table->unsignedInteger('daily_limit')->nullable()->after('throttle_per_minute');
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn('daily_limit');
        });
    }
};
