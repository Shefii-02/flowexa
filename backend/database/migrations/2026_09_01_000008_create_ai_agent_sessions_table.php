<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ai_agent_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->string('waha_session_id')->index();
            $table->string('contact_phone')->index();
            $table->json('conversation_history')->nullable();
            $table->string('current_intent')->nullable();
            $table->json('context')->nullable();
            $table->json('ai_config')->nullable();
            $table->timestamp('last_message_at')->nullable()->index();
            $table->enum('status', ['active', 'closed', 'transferred'])->default('active')->index();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_agent_sessions');
    }
};
