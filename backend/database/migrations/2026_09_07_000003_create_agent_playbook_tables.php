<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agent Playbooks — the per-vertical "what should the AI agent do" config.
 *
 *  - agent_playbook_templates : superadmin-owned category presets (LMS, Real
 *    Estate, Services…). A company clones one to get its own playbook.
 *  - agent_playbooks           : a company's live, editable playbook, optionally
 *    scoped to a single WhatsApp session.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('agent_playbook_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();                 // lms, real_estate, services…
            $table->string('name');
            $table->string('description', 500)->nullable();
            $table->string('icon', 16)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('default_config');                  // full playbook shape (see AgentPlaybook)
            $table->timestamps();
        });

        Schema::create('agent_playbooks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->string('session_id')->nullable()->index(); // null = applies to every session
            $table->string('template_key')->nullable();        // which template it was cloned from
            $table->string('business_type')->default('custom');
            $table->boolean('is_active')->default(true);

            $table->string('agent_name')->default('AI Assistant');
            $table->string('tone')->default('friendly-professional');
            $table->json('languages')->nullable();             // ['auto'] or ['en','ml','hi']
            $table->text('system_prompt')->nullable();
            $table->text('greeting_new')->nullable();
            $table->text('greeting_returning')->nullable();
            $table->text('closing_message')->nullable();
            $table->text('fallback_transfer_message')->nullable();

            $table->json('qualification_questions')->nullable();
            $table->json('handoff')->nullable();
            $table->json('escalation')->nullable();
            $table->json('payment')->nullable();
            $table->json('meta')->nullable();

            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->unique(['company_id', 'session_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_playbooks');
        Schema::dropIfExists('agent_playbook_templates');
    }
};
