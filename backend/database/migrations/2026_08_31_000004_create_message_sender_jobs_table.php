<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('message_sender_jobs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('campaign_name', 200)->nullable();
            $table->string('session_id', 100);
            $table->enum('type', ['personal', 'group', 'csv', 'label', 'from-chat', 'campaign'])->default('personal');
            $table->enum('status', ['pending', 'running', 'paused', 'stopped', 'scheduled', 'done'])->default('pending');
            $table->integer('total')->default(0);
            $table->integer('sent')->default(0);
            $table->integer('failed')->default(0);
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->integer('delay_ms')->default(3000);
            $table->boolean('unique_signature')->default(true);
            $table->json('log')->nullable();
            $table->json('message_payload')->nullable();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
            $table->index('company_id');
            $table->index(['company_id', 'status']);
            $table->index(['status', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_sender_jobs');
    }
};
