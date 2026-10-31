<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('openwa_message_sender_jobs', function (Blueprint $table) {
            // { pacing: {per_min_min, per_min_max, per_5min_cap}, variation: {enabled, variants[], share_numbers[], dots} }
            // Null = an older campaign: it keeps its fixed delay_ms between messages.
            $table->json('sending_options')->nullable()->after('message_payload');
        });
    }

    public function down(): void
    {
        Schema::table('openwa_message_sender_jobs', function (Blueprint $table) {
            $table->dropColumn('sending_options');
        });
    }
};
