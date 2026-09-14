<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Upgrades the AI on/off schedule from "one shared time range applied to a set of days" to a
 * real weekly grid — each day of the week gets its own start/end time (e.g. shorter hours on
 * Saturday, closed Sunday, different hours Mon-Fri). Stored as a JSON object keyed by weekday
 * (0=Sunday..6=Saturday); a day's absence from the object means the AI is off that day. The old
 * ai_schedule_days/start/end columns stay in place as the fallback AgentScheduleChecker uses
 * when a row hasn't been re-saved under the new per-day model yet — no data migration needed,
 * every playbook/account defaults to "always" until a company opens AI Schedule again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_playbooks', function (Blueprint $table) {
            $table->json('ai_schedule_hours')->nullable()->after('ai_schedule_timezone');
        });
        Schema::table('instagram_accounts', function (Blueprint $table) {
            $table->json('ai_schedule_hours')->nullable()->after('ai_schedule_timezone');
        });
    }

    public function down(): void
    {
        Schema::table('agent_playbooks', function (Blueprint $table) {
            $table->dropColumn('ai_schedule_hours');
        });
        Schema::table('instagram_accounts', function (Blueprint $table) {
            $table->dropColumn('ai_schedule_hours');
        });
    }
};
