<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('conversation_analyses', function (Blueprint $table) {
            $table->id();

            // ── Company / Contact ───────────────────────────────────────────
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('contact_id')->nullable()->index();
            $table->string('phone', 30);
            $table->string('message_id', 255)->nullable();
            $table->timestamp('analyzed_at')->useCurrent();

            // ── Sentiment ───────────────────────────────────────────────────
            $table->enum('sentiment', [
                'positive',
                'neutral',
                'negative',
                'mixed',
            ])->default('neutral');

            $table->decimal('sentiment_score', 3, 2)->default(0.50);
            $table->text('sentiment_reason')->nullable();

            // ── Intent ──────────────────────────────────────────────────────
            $table->enum('detected_intent', [
                'browsing',
                'price_inquiry',
                'product_inquiry',
                'complaint',
                'buying_signal',
                'ready_to_buy',
                'needs_followup',
                'not_interested',
                'existing_customer',
                'referral',
            ])->default('browsing');

            $table->decimal('intent_confidence', 3, 2)->default(0);
            $table->text('intent_details')->nullable();

            // ── Lead Scoring ────────────────────────────────────────────────
            $table->integer('lead_score')->default(0);
            $table->text('lead_score_reason')->nullable();
            $table->json('buying_signals')->nullable();
            $table->json('objections')->nullable();

            // ── Recommended Actions ─────────────────────────────────────────
            $table->json('recommended_actions')->nullable();
            $table->text('suggested_response')->nullable();
            $table->boolean('escalate_to_human')->default(false);
            $table->text('escalation_reason')->nullable();

            // ── Context + Meta ──────────────────────────────────────────────
            $table->json('context_snapshot')->nullable();
            $table->string('model_used', 100)->nullable();
            $table->integer('tokens_used')->default(0);
            $table->integer('analysis_ms')->default(0);

            $table->timestamps();

            // ── Composite Indexes ────────────────────────────────────────────
            $table->index(
                ['company_id', 'contact_id', 'analyzed_at'],
                'ca_company_contact_analyzed_idx'
            );

            $table->index(
                ['company_id', 'detected_intent', 'lead_score'],
                'ca_company_intent_score_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_analyses');
    }
};

