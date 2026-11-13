<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // NULL agent_id = shared by every agent of the company (how all existing rows behave).
        Schema::table('ai_knowledge_base', fn (Blueprint $t) => $t->unsignedBigInteger('agent_id')->nullable()->index()->after('company_id'));
        Schema::table('ai_pipelines', fn (Blueprint $t) => $t->unsignedBigInteger('agent_id')->nullable()->index()->after('company_id'));
        // The jobs this agent may do. NULL = the defaults for its role (AgentPlaybook::defaultAbilities).
        Schema::table('agent_playbooks', fn (Blueprint $t) => $t->json('abilities')->nullable()->after('channels'));
    }

    public function down(): void
    {
        Schema::table('agent_playbooks', fn (Blueprint $t) => $t->dropColumn('abilities'));
        Schema::table('ai_pipelines', fn (Blueprint $t) => $t->dropColumn('agent_id'));
        Schema::table('ai_knowledge_base', fn (Blueprint $t) => $t->dropColumn('agent_id'));
    }
};
