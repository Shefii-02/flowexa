<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What each WA Chat conversation is about — lead enquiry, technical issue, billing, complaint, existing customer, other — worked out from
 * the last few messages. One row per conversation (company + chat id), updated as the conversation moves on. Shadow mode first: the label is
 * stored and shown for review; nothing is routed or created from it.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('conversation_topics', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('contact_id')->nullable()->index();
            $t->unsignedBigInteger('session_id')->nullable();          // openwa_sessions.id the chat belongs to
            $t->string('chat_id', 80);                                 // gateway chat id (…@c.us / …@lid)
            $t->string('phone', 40)->nullable();
            $t->string('topic', 30);                                   // lead_enquiry | technical | billing | complaint | existing_customer | other
            $t->decimal('confidence', 3, 2)->default(0);
            $t->string('reason', 255)->nullable();
            $t->string('language', 20)->nullable();
            $t->string('source', 10)->default('rule');                 // rule | ai | staff (a staff choice is never overwritten)
            $t->char('msgs_hash', 40)->nullable();                     // the conversation text it was worked out from — unchanged text is not re-classified
            $t->unsignedSmallInteger('message_count')->default(0);
            $t->timestamp('classified_at')->nullable();
            $t->timestamps();

            $t->unique(['company_id', 'chat_id']);
            $t->index(['company_id', 'topic', 'classified_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_topics');
    }
};
