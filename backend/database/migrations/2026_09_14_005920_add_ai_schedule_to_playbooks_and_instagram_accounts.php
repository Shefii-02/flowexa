<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Always on" vs "scheduled hours" for the AI agent — configurable per account/session, not
 * just per company, since a company can run several WA Chat sessions, several WA Cloud
 * numbers, and several Instagram accounts, each potentially needing its own hours.
 *
 * AgentPlaybook already scopes per WA session via session_id (null = company-wide default),
 * so these columns there cover both WA Chat and WA Cloud — a company assigns a specific
 * playbook to a specific session/number the same way it already can today. Instagram has no
 * equivalent per-thread config point, so the same columns go directly on instagram_accounts.
 */
return new class extends Migration
{
    public function up(): void
    {
        $addScheduleColumns = function (Blueprint $table) {
            $table->string('ai_schedule_mode', 12)->default('always')->after('is_active'); // 'always' | 'scheduled'
            $table->json('ai_schedule_days')->nullable()->after('ai_schedule_mode');        // [1,2,3,4,5] (0=Sun..6=Sat)
            $table->time('ai_schedule_start')->nullable()->after('ai_schedule_days');
            $table->time('ai_schedule_end')->nullable()->after('ai_schedule_start');
            $table->string('ai_schedule_timezone', 64)->nullable()->after('ai_schedule_end');
        };

        Schema::table('agent_playbooks', $addScheduleColumns);
        Schema::table('instagram_accounts', $addScheduleColumns);
    }

    public function down(): void
    {
        Schema::table('agent_playbooks', function (Blueprint $table) {
            $table->dropColumn(['ai_schedule_mode', 'ai_schedule_days', 'ai_schedule_start', 'ai_schedule_end', 'ai_schedule_timezone']);
        });
        Schema::table('instagram_accounts', function (Blueprint $table) {
            $table->dropColumn(['ai_schedule_mode', 'ai_schedule_days', 'ai_schedule_start', 'ai_schedule_end', 'ai_schedule_timezone']);
        });
    }
};
