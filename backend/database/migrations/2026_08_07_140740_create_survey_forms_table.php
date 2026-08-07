<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('survey_forms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('description')->nullable();
            // Ordered list of questions: [{key, question_text, type: text|number|choice, options?: [], required}]
            $table->json('fields');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('survey_form_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('survey_form_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->string('phone', 20);
            $table->foreignId('flow_node_id')->nullable()->constrained('flow_nodes')->nullOnDelete();
            // {field_key: answer_value}
            $table->json('answers')->nullable();
            $table->enum('status', ['in_progress', 'completed', 'abandoned'])->default('in_progress');
            $table->unsignedInteger('current_field_index')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'survey_form_id', 'status']);
        });

        Schema::table('flow_nodes', function (Blueprint $table) {
            // 'survey': runs a survey_form question-by-question. 'template': sends an approved WA template.
            $table->foreignId('survey_form_id')->nullable()->after('lead_category')
                ->constrained('survey_forms')->nullOnDelete();
            $table->foreignId('wa_template_id')->nullable()->after('survey_form_id')
                ->constrained('wa_templates')->nullOnDelete();
        });

        Schema::table('flow_sessions', function (Blueprint $table) {
            // Set while a survey is in progress for this session — while set, incoming text
            // is treated as an answer rather than routed through normal node matching.
            $table->foreignId('active_survey_response_id')->nullable()->after('context')
                ->constrained('survey_form_responses')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('flow_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('active_survey_response_id');
        });
        Schema::table('flow_nodes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('wa_template_id');
            $table->dropConstrainedForeignId('survey_form_id');
        });
        Schema::dropIfExists('survey_form_responses');
        Schema::dropIfExists('survey_forms');
    }
};
