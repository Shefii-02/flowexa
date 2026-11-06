<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Instagram Login message ids are ~150-char base64 strings; the original VARCHAR(80) rejected them,
// which failed the run right after the DM was actually delivered.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE instagram_messages MODIFY ig_message_id VARCHAR(255) NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE instagram_messages MODIFY ig_message_id VARCHAR(80) NULL');
    }
};
