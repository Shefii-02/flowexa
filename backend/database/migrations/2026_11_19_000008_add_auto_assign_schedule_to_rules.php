<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('lead_assignment_rules', function (Blueprint $table) {
            // instant = route every new lead the moment it arrives (5-min safety-net sweep); scheduled = a cron run picks up waiting leads
            $table->enum('auto_assign_run_mode', ['instant', 'scheduled'])->default('instant');
            $table->unsignedSmallInteger('auto_assign_interval_minutes')->default(15);
            $table->time('auto_assign_run_start')->nullable();   // optional daily run window (rule timezone)
            $table->time('auto_assign_run_end')->nullable();
            $table->timestamp('auto_assign_last_run_at')->nullable();
            $table->unsignedInteger('auto_assign_last_run_count')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('lead_assignment_rules', function (Blueprint $table) {
            $table->dropColumn(['auto_assign_run_mode', 'auto_assign_interval_minutes', 'auto_assign_run_start', 'auto_assign_run_end', 'auto_assign_last_run_at', 'auto_assign_last_run_count']);
        });
    }
};
