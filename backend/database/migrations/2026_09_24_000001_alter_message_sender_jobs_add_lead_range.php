<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Adds a "Lead" recipient tab to wa-chat's message-sender: recipients are every lead
// created within a from/to date range (mirroring leads.created_from/created_to) rather
// than a manual pick. The range itself is persisted on the job row so campaign history
// shows what was targeted, not just the resolved phone snapshot in `log`. doctrine/dbal
// isn't installed, so the enum widen goes through raw SQL rather than ->change(), same
// as 2026_09_05_000002_alter_message_sender_type_enums_add_chat.php.
return new class extends Migration {
    public function up(): void
    {
        Schema::table('message_sender_jobs', function (Blueprint $table) {
            if (!Schema::hasColumn('message_sender_jobs', 'lead_created_from')) {
                $table->date('lead_created_from')->nullable()->after('type');
            }
            if (!Schema::hasColumn('message_sender_jobs', 'lead_created_to')) {
                $table->date('lead_created_to')->nullable()->after('lead_created_from');
            }
        });

        DB::statement("ALTER TABLE message_sender_jobs MODIFY type ENUM('personal','group','csv','label','chat','from-chat','lead','campaign') NOT NULL DEFAULT 'personal'");
        DB::statement("ALTER TABLE waha_message_logs MODIFY recipient_type ENUM('personal','group','csv','label','chat','from-chat','lead','campaign') NOT NULL DEFAULT 'personal'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE message_sender_jobs MODIFY type ENUM('personal','group','csv','label','chat','from-chat','campaign') NOT NULL DEFAULT 'personal'");
        DB::statement("ALTER TABLE waha_message_logs MODIFY recipient_type ENUM('personal','group','csv','label','chat','from-chat','campaign') NOT NULL DEFAULT 'personal'");

        Schema::table('message_sender_jobs', function (Blueprint $table) {
            foreach (['lead_created_from', 'lead_created_to'] as $col) {
                if (Schema::hasColumn('message_sender_jobs', $col)) $table->dropColumn($col);
            }
        });
    }
};
