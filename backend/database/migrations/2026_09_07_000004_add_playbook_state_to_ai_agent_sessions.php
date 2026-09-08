<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Slot-filling / qualification state for an AI agent conversation, plus the
 * detected language + writing style so replies can mirror the customer.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('ai_agent_sessions', function (Blueprint $table) {
            $table->unsignedBigInteger('playbook_id')->nullable()->after('company_id');
            $table->json('collected_slots')->nullable()->after('context');
            $table->string('qualification_status')->default('pending')->after('collected_slots');
            // pending | in_progress | complete | escalated | skipped
            $table->string('pending_question_key')->nullable()->after('qualification_status');
            $table->string('detected_language', 32)->nullable()->after('pending_question_key');
            $table->string('detected_style', 64)->nullable()->after('detected_language');
            // e.g. "malayalam", "manglish", "english", "hinglish", "mixed"
        });
    }

    public function down(): void
    {
        Schema::table('ai_agent_sessions', function (Blueprint $table) {
            $table->dropColumn([
                'playbook_id', 'collected_slots', 'qualification_status',
                'pending_question_key', 'detected_language', 'detected_style',
            ]);
        });
    }
};
