<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `wa_export_jobs.export_type` shipped as a fixed ENUM, so adding a new export
 * (contact_list) blew up on insert with a data-truncation error. Widen it to a
 * plain string — the controller decides the allowed set.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement("ALTER TABLE wa_export_jobs MODIFY export_type VARCHAR(40) NOT NULL");
    }

    public function down(): void
    {
        DB::statement(
            "UPDATE wa_export_jobs SET export_type = 'chat_list' " .
            "WHERE export_type NOT IN ('chat_list','group_list','group_participants','message_history')"
        );
        DB::statement(
            "ALTER TABLE wa_export_jobs MODIFY export_type ENUM('chat_list','group_list','group_participants','message_history') NOT NULL"
        );
    }
};
