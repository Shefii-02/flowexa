<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// The frontend's "From Chat" recipient tab sends type/recipient_type = 'chat', but both enums here
// only ever allowed 'from-chat' — a value nothing produces. Every scheduled/launched campaign built
// from that tab would fail at the database insert despite passing request validation (which was
// separately widened to accept 'chat'). Adds 'chat' to both enums; doctrine/dbal isn't installed so
// this goes through raw SQL rather than Schema::table(...)->change(). 'from-chat' is kept in case
// any row already used it.
return new class extends Migration {
    public function up(): void
    {
        DB::statement("ALTER TABLE message_sender_jobs MODIFY type ENUM('personal','group','csv','label','chat','from-chat','campaign') NOT NULL DEFAULT 'personal'");
        DB::statement("ALTER TABLE waha_message_logs MODIFY recipient_type ENUM('personal','group','csv','label','chat','from-chat','campaign') NOT NULL DEFAULT 'personal'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE message_sender_jobs MODIFY type ENUM('personal','group','csv','label','from-chat','campaign') NOT NULL DEFAULT 'personal'");
        DB::statement("ALTER TABLE waha_message_logs MODIFY recipient_type ENUM('personal','group','csv','label','from-chat','campaign') NOT NULL DEFAULT 'personal'");
    }
};
