<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('follow_up_queue', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('rule_id')->nullable()->index();
            $table->string('session_id')->index();
            $table->string('contact_phone');
            $table->string('contact_name')->nullable();
            $table->json('message_payload');
            $table->timestamp('scheduled_at')->index();
            $table->timestamp('executed_at')->nullable();
            $table->enum('status', ['pending', 'sent', 'failed', 'cancelled'])->default('pending')->index();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->foreign('rule_id')->references('id')->on('automation_rules')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('follow_up_queue');
    }
};
