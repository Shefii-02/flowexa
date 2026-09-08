<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WhatsApp Business Calling API events. One row per call, upserted as the
 * `calls` webhook field delivers connect / status / terminate events for it.
 * Feeds the WA Cloud "Inbox Analytics" page alongside `message_logs`.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('wa_calls', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('wa_call_id', 128)->nullable();
            $table->string('phone_number_id', 64)->nullable();
            $table->unsignedBigInteger('contact_id')->nullable();
            $table->unsignedBigInteger('conversation_id')->nullable();
            $table->unsignedBigInteger('assigned_to')->nullable(); // staff/user who owns the linked conversation

            $table->string('direction', 12)->default('inbound');   // inbound | outbound
            $table->string('status', 20)->default('ringing');       // ringing | connected | completed | missed | rejected | failed | no_answer | canceled
            $table->string('from_phone', 30)->nullable();
            $table->string('to_phone', 30)->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();

            $table->json('raw')->nullable();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->unique(['company_id', 'wa_call_id']);
            $table->index(['company_id', 'created_at']);
            $table->index(['company_id', 'direction', 'status']);
            $table->index(['company_id', 'assigned_to']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_calls');
    }
};
