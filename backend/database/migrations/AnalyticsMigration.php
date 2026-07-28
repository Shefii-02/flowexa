<?php
// ════════════════════════════════════════════════════════════════════════════
// ADVANCED ANALYTICS MIGRATION
// database/migrations/2024_01_01_000003_create_analytics_tables.php
// ════════════════════════════════════════════════════════════════════════════

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // ── Daily analytics snapshot (pre-aggregated for fast queries) ────────
        Schema::create('analytics_daily', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->date('date');
            // Messages
            $t->unsignedInteger('messages_sent')->default(0);
            $t->unsignedInteger('messages_delivered')->default(0);
            $t->unsignedInteger('messages_read')->default(0);
            $t->unsignedInteger('messages_failed')->default(0);
            $t->unsignedInteger('messages_inbound')->default(0);
            // Contacts
            $t->unsignedInteger('contacts_new')->default(0);
            $t->unsignedInteger('contacts_opted_out')->default(0);
            // Leads
            $t->unsignedInteger('leads_created')->default(0);
            $t->unsignedInteger('leads_enrolled')->default(0);
            $t->unsignedInteger('leads_lost')->default(0);
            // Campaigns
            $t->unsignedInteger('campaigns_launched')->default(0);
            // Wallet
            $t->unsignedInteger('wallet_debited')->default(0);
            $t->timestamps();

            $t->unique(['company_id', 'date']);
            $t->index(['company_id', 'date']);
        });

        // ── Campaign A/B test results ──────────────────────────────────────────
        Schema::create('campaign_ab_tests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('name', 100);
            $t->foreignId('campaign_a_id')->constrained('campaigns')->cascadeOnDelete();
            $t->foreignId('campaign_b_id')->nullable()->constrained('campaigns')->nullOnDelete();
            $t->string('winner', 1)->nullable(); // 'A' | 'B'
            $t->string('metric', 20)->default('delivery_rate'); // delivery_rate | read_rate
            $t->timestamps();
        });

        // ── Lead scoring rules ─────────────────────────────────────────────────
        Schema::create('lead_scoring_rules', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('event', 50);         // flow_reply | campaign_read | follow_up_done
            $t->integer('points');           // positive or negative
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        // ── Lead scores ────────────────────────────────────────────────────────
        Schema::create('lead_scores', function (Blueprint $t) {
            $t->id();
            $t->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->integer('score')->default(0);
            $t->json('breakdown')->nullable(); // {event: points, ...}
            $t->timestamps();

            $t->unique('lead_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_scores');
        Schema::dropIfExists('lead_scoring_rules');
        Schema::dropIfExists('campaign_ab_tests');
        Schema::dropIfExists('analytics_daily');
    }
};
