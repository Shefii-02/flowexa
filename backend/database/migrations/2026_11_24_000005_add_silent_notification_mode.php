<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Notification mode "silent": assign the top pick directly (like Auto-assign) but send the staff member no notification. */
return new class extends Migration {
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE lead_assignment_rules MODIFY notification_mode ENUM('auto','uber','hybrid','silent') NOT NULL DEFAULT 'hybrid'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::table('lead_assignment_rules')->where('notification_mode', 'silent')->update(['notification_mode' => 'auto']);
            DB::statement("ALTER TABLE lead_assignment_rules MODIFY notification_mode ENUM('auto','uber','hybrid') NOT NULL DEFAULT 'hybrid'");
        }
    }
};
