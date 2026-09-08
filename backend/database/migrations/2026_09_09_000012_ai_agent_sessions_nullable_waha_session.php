<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // An AI agent session can be opened for a lead that arrived from the website widget, an
        // Instagram DM, or the lead-assignment out-of-hours hand-off — none of which has a
        // WhatsApp engine session. LeadAssignmentEngine::startAiAgent already passes null here.
        Schema::table('ai_agent_sessions', function (Blueprint $t) {
            $t->string('waha_session_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('ai_agent_sessions', function (Blueprint $t) {
            $t->string('waha_session_id')->nullable(false)->change();
        });
    }
};
