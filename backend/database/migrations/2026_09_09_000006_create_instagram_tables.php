<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A connected Instagram business/creator account (linked to a Facebook Page).
        Schema::create('instagram_accounts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('connected_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('ig_user_id', 40)->unique();          // IG Business Account id
            $t->string('username', 120)->nullable();
            $t->string('name', 200)->nullable();
            $t->string('page_id', 40)->nullable();            // the Facebook Page it's linked to
            $t->string('page_name', 200)->nullable();
            $t->text('access_token');                          // page access token (encrypted via cast)
            $t->string('profile_picture_url', 600)->nullable();
            $t->unsignedInteger('followers_count')->default(0);

            // AI auto-reply for DMs
            $t->boolean('ai_enabled')->default(false);
            $t->text('ai_persona')->nullable();               // brand voice / rules for the agent
            $t->string('ai_language', 20)->default('auto');   // auto | en | ...
            $t->boolean('mirror_customer_style')->default(true);

            $t->boolean('is_active')->default(true);
            $t->timestamp('token_expires_at')->nullable();
            $t->timestamp('last_synced_at')->nullable();
            $t->timestamps();
            $t->softDeletes();

            $t->index(['company_id', 'is_active']);
        });

        // Keyword-triggered automations: a comment (or DM) containing a keyword fires a DM reply.
        Schema::create('instagram_automations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('instagram_account_id')->constrained('instagram_accounts')->cascadeOnDelete();
            $t->string('name', 150);
            $t->string('trigger', 20)->default('comment');    // comment | dm | story_reply
            $t->json('keywords');                              // ["price", "link", "buy"]
            $t->string('match_type', 10)->default('any');      // any | all | exact
            $t->string('media_scope', 12)->default('all');     // all | selected
            $t->json('media_ids')->nullable();                 // IG media ids when media_scope = selected
            $t->text('dm_message');                            // the DM to send (supports {{name}})
            $t->text('public_reply')->nullable();              // optional public comment reply
            $t->boolean('reply_once_per_user')->default(true);
            $t->boolean('handoff_to_ai')->default(false);      // after the DM, let the AI agent take the thread
            $t->boolean('is_active')->default(true);
            $t->unsignedSmallInteger('priority')->default(0);  // higher wins when several match
            $t->unsignedInteger('triggered_count')->default(0);
            $t->timestamp('last_triggered_at')->nullable();
            $t->timestamps();
            $t->softDeletes();

            $t->index(['instagram_account_id', 'is_active', 'trigger'], 'ig_auto_acct_active_trigger_idx');
        });

        // One DM thread with one IG user.
        Schema::create('instagram_conversations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('instagram_account_id')->constrained('instagram_accounts')->cascadeOnDelete();
            $t->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $t->string('participant_id', 40);                 // the customer's IGSID
            $t->string('participant_username', 120)->nullable();
            $t->string('status', 12)->default('open');        // open | snoozed | closed
            $t->boolean('ai_enabled')->default(true);         // per-thread override of the account setting
            $t->unsignedInteger('unread_count')->default(0);
            $t->text('last_message_preview')->nullable();
            $t->timestamp('last_message_at')->nullable();
            $t->timestamp('last_inbound_at')->nullable();     // drives the 24h messaging-window check
            $t->timestamps();

            $t->unique(['instagram_account_id', 'participant_id'], 'ig_convo_acct_participant_uq');
            $t->index(['company_id', 'status', 'last_message_at'], 'ig_convo_company_status_idx');
        });

        Schema::create('instagram_messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('instagram_conversation_id')->constrained('instagram_conversations')->cascadeOnDelete();
            $t->string('ig_message_id', 80)->nullable()->index();
            $t->string('direction', 3);                       // in | out
            $t->string('source', 12)->default('manual');      // inbound | manual | automation | ai
            $t->text('text')->nullable();
            $t->json('attachments')->nullable();
            $t->string('status', 12)->nullable();             // sent | delivered | failed
            $t->text('error')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->timestamps();

            $t->index(['instagram_conversation_id', 'created_at'], 'ig_msg_convo_created_idx');
        });

        // Dedup + audit for comment triggers — one row per IG comment we acted on.
        Schema::create('instagram_comment_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('instagram_account_id')->constrained('instagram_accounts')->cascadeOnDelete();
            $t->foreignId('instagram_automation_id')->nullable()->constrained('instagram_automations')->nullOnDelete();
            $t->string('ig_comment_id', 80)->unique();
            $t->string('media_id', 60)->nullable();
            $t->string('from_id', 40)->nullable();
            $t->string('from_username', 120)->nullable();
            $t->text('comment_text')->nullable();
            $t->string('action', 20)->default('none');        // dm_sent | public_replied | both | none | failed
            $t->text('error')->nullable();
            $t->timestamps();

            $t->index(['instagram_account_id', 'created_at'], 'ig_cmt_evt_acct_created_idx');
            $t->index(['instagram_automation_id', 'from_id'], 'ig_cmt_evt_auto_from_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instagram_comment_events');
        Schema::dropIfExists('instagram_messages');
        Schema::dropIfExists('instagram_conversations');
        Schema::dropIfExists('instagram_automations');
        Schema::dropIfExists('instagram_accounts');
    }
};
