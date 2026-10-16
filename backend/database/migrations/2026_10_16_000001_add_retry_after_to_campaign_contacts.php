<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_contacts', function (Blueprint $table) {
            // Earliest time a failed number may be retried (e.g. 24h after Meta's 131049 marketing cap).
            $table->timestamp('retry_after')->nullable()->after('resent_at');
        });
    }

    public function down(): void
    {
        Schema::table('campaign_contacts', function (Blueprint $table) {
            $table->dropColumn('retry_after');
        });
    }
};
