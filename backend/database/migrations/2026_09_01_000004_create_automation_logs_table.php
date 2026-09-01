<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('automation_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('rule_id')->nullable()->index();
            $table->string('session_id')->index();
            $table->string('contact_phone');
            $table->string('rule_type');
            $table->json('trigger_data')->nullable();
            $table->text('action_taken')->nullable();
            $table->json('result')->nullable();
            $table->enum('status', ['success', 'failed', 'skipped'])->default('success');
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->foreign('rule_id')->references('id')->on('automation_rules')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_logs');
    }
};
