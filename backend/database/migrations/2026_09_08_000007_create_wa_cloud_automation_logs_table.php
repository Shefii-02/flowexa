<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Execution log for WA Cloud automation rules. `status` is a plain string
 * (success | failed | skipped) from day one.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('wa_cloud_automation_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('rule_id')->nullable();
            $table->unsignedBigInteger('wa_phone_number_id')->nullable();
            $table->string('contact_phone', 30);
            $table->string('rule_type', 40);
            $table->json('trigger_data')->nullable();
            $table->text('action_taken')->nullable();
            $table->json('result')->nullable();
            $table->string('status', 20)->default('success');
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('rule_id')->references('id')->on('wa_cloud_automation_rules')->nullOnDelete();
            $table->index(['company_id', 'created_at']);
            $table->index('rule_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_cloud_automation_logs');
    }
};
