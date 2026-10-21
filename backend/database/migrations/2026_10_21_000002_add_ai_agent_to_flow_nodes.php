<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flow_nodes', function (Blueprint $t) {
            // AI-agent (knowledge_base) nodes: answer in the voice of this existing agent playbook ...
            $t->unsignedBigInteger('ai_agent_id')->nullable()->after('wa_template_id');
            // ... and only from these knowledge-base documents (null = the whole knowledge base).
            $t->json('ai_kb_ids')->nullable()->after('ai_agent_id');
        });
    }

    public function down(): void
    {
        Schema::table('flow_nodes', fn (Blueprint $t) => $t->dropColumn(['ai_agent_id', 'ai_kb_ids']));
    }
};
