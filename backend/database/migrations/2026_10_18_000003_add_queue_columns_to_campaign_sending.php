<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_contacts', function (Blueprint $table) {
            // When this pending row's queue job is due to fire (null = not queued yet).
            $table->timestamp('scheduled_for')->nullable()->after('retry_after');
            $table->index(['campaign_id', 'status', 'scheduled_for'], 'cc_campaign_status_sched_idx');
        });

        Schema::table('message_logs', function (Blueprint $table) {
            // Links a log row to its campaign_contacts row so retries UPDATE the same log row
            // instead of adding a new one.
            $table->unsignedBigInteger('campaign_contact_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('campaign_contacts', function (Blueprint $table) {
            $table->dropIndex('cc_campaign_status_sched_idx');
            $table->dropColumn('scheduled_for');
        });
        Schema::table('message_logs', function (Blueprint $table) {
            $table->dropColumn('campaign_contact_id');
        });
    }
};
