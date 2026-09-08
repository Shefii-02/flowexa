<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_widgets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('public_key', 40)->unique();          // goes in the <script> tag
            $t->string('name', 120)->default('Website chat');
            $t->boolean('is_active')->default(true);

            // Which vertical the widget agent behaves as. Null = use the company default.
            $t->string('industry_template', 30)->nullable();

            $t->string('agent_name', 80)->default('Assistant');
            $t->text('greeting')->default('Hi! 👋 How can I help you today?');
            // { primary_color, position:'right'|'left', avatar_url, launcher_text, dark }
            $t->json('branding')->nullable();
            // Domains the widget may run on (empty = allow any). Exact host match.
            $t->json('allowed_origins')->nullable();

            // Where a qualified lead is announced.
            $t->json('notify_emails')->nullable();
            $t->json('notify_whatsapp')->nullable();          // ["9198xxxxxxx", ...]
            $t->string('wa_session_id', 100)->nullable();     // engine session used to send the WA alert

            $t->unsignedInteger('conversations_count')->default(0);
            $t->unsignedInteger('leads_count')->default(0);

            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('widget_conversations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('chat_widget_id')->constrained('chat_widgets')->cascadeOnDelete();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('session_token', 64)->unique();        // held by the browser
            $t->string('visitor_name', 120)->nullable();
            $t->string('visitor_phone', 40)->nullable();
            $t->string('visitor_email', 160)->nullable();
            // The template's qualification_fields as they get answered.
            $t->json('collected')->nullable();
            $t->json('matched_listing_ids')->nullable();      // last set the agent surfaced
            $t->string('status', 12)->default('active');      // active | qualified | closed
            $t->timestamp('qualified_at')->nullable();
            $t->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $t->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();
            $t->string('page_url', 500)->nullable();
            $t->string('visitor_ip', 45)->nullable();
            $t->timestamp('last_message_at')->nullable();
            $t->timestamps();

            $t->index(['company_id', 'status', 'last_message_at'], 'widget_convo_company_status_idx');
        });

        Schema::create('widget_messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('widget_conversation_id')->constrained('widget_conversations')->cascadeOnDelete();
            $t->string('role', 10);                           // visitor | agent
            $t->text('text');
            $t->timestamp('created_at')->nullable();

            $t->index(['widget_conversation_id', 'created_at'], 'widget_msg_convo_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('widget_messages');
        Schema::dropIfExists('widget_conversations');
        Schema::dropIfExists('chat_widgets');
    }
};
