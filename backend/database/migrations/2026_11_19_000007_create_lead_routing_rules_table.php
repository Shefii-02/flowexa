<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('lead_routing_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->enum('match_type', ['category', 'campaign']);
            $table->string('category', 120)->nullable();          // match_type=category: the lead category name
            $table->unsignedBigInteger('campaign_id')->nullable(); // match_type=campaign: one campaign; null = any campaign (use the period)
            $table->dateTime('starts_at')->nullable();            // optional period the rule is live in
            $table->dateTime('ends_at')->nullable();
            $table->json('staff_ids');                            // only these employees can receive the matching leads
            $table->enum('strategy', ['algorithm', 'round_robin'])->nullable(); // null = the company's default method
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('priority')->default(100); // lower runs first
            $table->timestamps();
            $table->index(['company_id', 'is_active']);
        });

        Schema::table('lead_assignments', function (Blueprint $table) {
            $table->string('routing_strategy', 20)->nullable();
            $table->unsignedBigInteger('routing_rule_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('lead_assignments', function (Blueprint $table) {
            $table->dropColumn(['routing_strategy', 'routing_rule_id']);
        });
        Schema::dropIfExists('lead_routing_rules');
    }
};
