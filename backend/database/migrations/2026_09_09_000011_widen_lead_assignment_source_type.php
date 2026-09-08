<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Add the new AI-agent channels (website widget, Instagram DM, WhatsApp) to the source enum.
        DB::statement(
            "ALTER TABLE lead_assignments MODIFY source_type "
            . "ENUM('wa_chat','meta_api','campaign','organic','flow_builder','manual','website_widget','instagram','whatsapp') "
            . "NOT NULL DEFAULT 'organic'"
        );
    }

    public function down(): void
    {
        DB::statement("UPDATE lead_assignments SET source_type = 'organic' WHERE source_type IN ('website_widget','instagram','whatsapp')");
        DB::statement(
            "ALTER TABLE lead_assignments MODIFY source_type "
            . "ENUM('wa_chat','meta_api','campaign','organic','flow_builder','manual') "
            . "NOT NULL DEFAULT 'organic'"
        );
    }
};
