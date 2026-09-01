<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ai_pipelines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('trigger_type', ['message', 'webhook', 'cron', 'manual'])->default('manual');
            $table->json('trigger_config')->nullable();
            $table->json('steps');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
        });

        Schema::create('ai_pipeline_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pipeline_id')->index();
            $table->unsignedBigInteger('company_id')->index();
            $table->string('triggered_by')->nullable();
            $table->json('trigger_data')->nullable();
            $table->enum('status', ['pending', 'running', 'completed', 'failed'])->default('pending')->index();
            $table->json('steps_log')->nullable();
            $table->json('result')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('pipeline_id')->references('id')->on('ai_pipelines')->onDelete('cascade');
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_pipeline_runs');
        Schema::dropIfExists('ai_pipelines');
    }
};
