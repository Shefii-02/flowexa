<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('meta_ai_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained('companies')->cascadeOnDelete();
            $table->boolean('is_enabled')->default(false);

            // ── Meta Business API ──────────────────────────────────────────────
            $table->string('meta_app_id', 100)->nullable();
            $table->text('meta_app_secret')->nullable();        // encrypted
            $table->text('meta_access_token')->nullable();      // encrypted
            $table->string('meta_phone_number_id', 100)->nullable();
            $table->string('meta_waba_id', 100)->nullable();

            // ── Meta AI / Llama ────────────────────────────────────────────────
            $table->boolean('meta_ai_enabled')->default(false);
            $table->string('meta_ai_model', 100)->default('meta-llama/Llama-3.1-8B-Instruct');
            $table->text('meta_ai_api_key')->nullable();        // encrypted

            // ── Analysis Settings ──────────────────────────────────────────────
            $table->boolean('analyze_on_message')->default(true);
            $table->boolean('analyze_sentiment')->default(true);
            $table->boolean('detect_buying_signals')->default(true);
            $table->boolean('auto_qualify_leads')->default(true);
            $table->boolean('auto_create_tasks')->default(false);
            $table->decimal('hand_off_threshold', 3, 2)->default(0.85);

            // ── Context Injection ──────────────────────────────────────────────
            $table->boolean('inject_company_profile')->default(true);
            $table->boolean('inject_services')->default(true);
            $table->boolean('inject_pricing')->default(true);
            $table->boolean('inject_past_conversations')->default(true);
            $table->integer('max_context_messages')->default(20);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_ai_configs');
    }
};
