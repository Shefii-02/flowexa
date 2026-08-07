<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        //
        Schema::create('wa_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('wa_phone_number_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->string('phone', 20)->index(); // always kept even if contact_id is null (unknown number)
            $table->string('contact_name')->nullable(); // WhatsApp profile name from the webhook payload
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('status', ['open', 'pending', 'closed'])->default('open');
            $table->timestamp('last_message_at')->nullable();
            $table->unsignedInteger('unread_count')->default(0);
            $table->timestamps();

            $table->unique(['company_id', 'phone']); // one conversation thread per customer number
            $table->index(['company_id', 'status', 'last_message_at']);
        });

        Schema::create('wa_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('wa_conversations')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->enum('direction', ['inbound', 'outbound']);
            $table->enum('sender_type', ['customer', 'agent', 'system', 'bot']);
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete(); // agent user, if outbound
            $table->string('wa_message_id')->nullable()->index(); // Meta's message id, for status webhook matching
            $table->string('type', 30)->default('text'); // text|image|video|document|audio|location|interactive|button|template
            $table->json('content'); // shape depends on `type` — body text, media url+caption, button reply, etc.
            $table->enum('status', ['queued', 'sent', 'delivered', 'read', 'failed'])->default('sent');
            $table->text('failure_reason')->nullable();
            $table->timestamp('status_updated_at')->nullable();
            $table->timestamps();


            $table->index(['conversation_id', 'created_at']);

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
        Schema::dropIfExists('wa_messages');
        Schema::dropIfExists('wa_conversations');
    }
};
