<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('waha_message_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('job_id')->nullable();
            $table->string('campaign_name', 200)->nullable();
            $table->string('session_id', 100);
            $table->string('recipient_name', 150)->nullable();
            $table->string('recipient_phone', 30);
            $table->enum('recipient_type', ['personal', 'group', 'csv', 'label', 'from-chat', 'campaign'])->default('personal');
            $table->enum('message_type', ['text', 'image', 'video', 'audio', 'document', 'template', 'poll', 'location', 'contact'])->default('text');
            $table->enum('status', ['pending', 'sent', 'failed', 'skipped'])->default('pending');
            $table->text('error_message')->nullable();
            $table->string('waha_message_id', 255)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->foreign('job_id')->references('id')->on('message_sender_jobs')->onDelete('set null');
            $table->index(['company_id', 'job_id', 'status']);
            $table->index('company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waha_message_logs');
    }
};
