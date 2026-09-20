<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Attributes a lead to the open-wa bulk campaign (message_sender_jobs) it was created
// from: when a customer replies to a WhatsApp message while that campaign's
// lead_created_from/lead_created_to window still covers today, the auto-created lead
// gets stamped with the campaign that reached them. Unconstrained-by-choice would be
// wrong here — unlike leads.origin_id (which points at a different table depending on
// origin_type), this column only ever means one thing, so it gets a real FK.
return new class extends Migration {
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            if (!Schema::hasColumn('leads', 'wa_open_campaign_id')) {
                $table->foreignId('wa_open_campaign_id')->nullable()->after('campaign_id')
                    ->constrained('message_sender_jobs')->nullOnDelete();
            }
        });

        // recipient_phone had no index at all — every inbound reply would otherwise
        // force a full scan of waha_message_logs to find which campaign(s) messaged it.
        Schema::table('waha_message_logs', function (Blueprint $table) {
            $table->index(['company_id', 'recipient_phone', 'sent_at'], 'waha_message_logs_phone_idx');
        });
    }

    public function down(): void
    {
        Schema::table('waha_message_logs', function (Blueprint $table) {
            $table->dropIndex('waha_message_logs_phone_idx');
        });

        Schema::table('leads', function (Blueprint $table) {
            if (Schema::hasColumn('leads', 'wa_open_campaign_id')) {
                $table->dropConstrainedForeignId('wa_open_campaign_id');
            }
        });
    }
};
