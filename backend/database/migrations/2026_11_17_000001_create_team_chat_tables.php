<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Internal team chat (mobile "Chats" tab): 1:1 chats + groups, with replies, attachments, edit / delete,
 * reactions, read receipts, mentions, mute and group admin roles.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_chats', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->index();
            $t->string('type', 10);                       // direct | group
            $t->string('name')->nullable();               // groups only
            $t->string('avatar_url')->nullable();
            $t->string('direct_key', 40)->nullable();     // "minId:maxId" — one direct chat per pair
            $t->unsignedBigInteger('created_by')->nullable();
            $t->boolean('only_admins_post')->default(false);
            $t->boolean('only_admins_edit')->default(false);   // only admins may rename / change the group photo
            $t->boolean('only_admins_add')->default(false);    // only admins may add members
            $t->unsignedBigInteger('last_message_id')->nullable();
            $t->timestamp('last_message_at')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'direct_key']);
        });

        Schema::create('team_chat_members', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('chat_id');
            $t->unsignedBigInteger('user_id');
            $t->string('role', 10)->default('member');    // admin | member
            $t->unsignedBigInteger('last_delivered_message_id')->default(0);   // newest message that reached this member's device
            $t->unsignedBigInteger('last_read_message_id')->default(0);
            $t->boolean('muted')->default(false);
            $t->timestamp('muted_until')->nullable();       // null + muted = until turned off
            $t->timestamp('joined_at')->nullable();
            $t->timestamp('left_at')->nullable();         // set when removed / left; history stays
            $t->timestamps();
            $t->unique(['chat_id', 'user_id']);
            $t->index(['user_id', 'left_at'], 'tcmem_user_active');
            $t->foreign('chat_id')->references('id')->on('team_chats')->cascadeOnDelete();
        });

        Schema::create('team_chat_messages', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('chat_id');
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('sender_id')->nullable();   // null = system message
            $t->string('type', 10)->default('text');            // text | image | video | audio | file | location | contact | system
            $t->text('body')->nullable();
            $t->string('attachment_url')->nullable();
            $t->string('attachment_name')->nullable();
            $t->unsignedInteger('attachment_size')->nullable();
            $t->string('attachment_mime', 100)->nullable();
            $t->unsignedInteger('duration_seconds')->nullable();   // voice notes / video
            $t->decimal('latitude', 10, 7)->nullable();             // location shares
            $t->decimal('longitude', 10, 7)->nullable();
            $t->string('location_label')->nullable();
            $t->string('contact_name')->nullable();                 // contact shares
            $t->string('contact_phone', 40)->nullable();
            $t->boolean('forwarded')->default(false);
            $t->unsignedBigInteger('reply_to_id')->nullable();
            $t->string('client_id', 40)->nullable();                // idempotency key: a retried send returns the original message
            $t->json('mentions')->nullable();                   // user ids
            $t->timestamp('edited_at')->nullable();
            $t->timestamp('deleted_at')->nullable();             // deleted for everyone — content is KEPT so company admins can review it
            $t->unsignedBigInteger('deleted_by')->nullable();
            $t->timestamps();
            $t->index(['chat_id', 'id']);
            $t->unique(['chat_id', 'sender_id', 'client_id'], 'tcm_idempotency');
            $t->index(['chat_id', 'type', 'id'], 'tcm_media');          // media / docs / links listing
            $t->foreign('chat_id')->references('id')->on('team_chats')->cascadeOnDelete();
        });

        Schema::create('team_chat_reactions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('message_id');
            $t->unsignedBigInteger('user_id');
            $t->string('emoji', 16);
            $t->timestamp('created_at')->nullable();
            $t->unique(['message_id', 'user_id', 'emoji']);
            $t->foreign('message_id')->references('id')->on('team_chat_messages')->cascadeOnDelete();
        });

        // Per-message, per-recipient delivery / read times (message info screen: sender + company admins only).
        Schema::create('team_chat_receipts', function (Blueprint $t) {
            $t->unsignedBigInteger('message_id');
            $t->unsignedBigInteger('user_id');
            $t->timestamp('delivered_at')->nullable();
            $t->timestamp('read_at')->nullable();
            $t->primary(['message_id', 'user_id']);
            $t->foreign('message_id')->references('id')->on('team_chat_messages')->cascadeOnDelete();
        });

        // Starred messages (personal bookmarks) and pinned messages (shared, shown at the top of the chat).
        Schema::create('team_chat_stars', function (Blueprint $t) {
            $t->unsignedBigInteger('message_id');
            $t->unsignedBigInteger('user_id');
            $t->timestamp('created_at')->nullable();
            $t->primary(['message_id', 'user_id']);
            $t->index('user_id');
            $t->foreign('message_id')->references('id')->on('team_chat_messages')->cascadeOnDelete();
        });
        Schema::create('team_chat_pins', function (Blueprint $t) {
            $t->unsignedBigInteger('chat_id');
            $t->unsignedBigInteger('message_id');
            $t->unsignedBigInteger('pinned_by')->nullable();
            $t->timestamp('pinned_at')->nullable();
            $t->primary(['chat_id', 'message_id']);
            $t->foreign('message_id')->references('id')->on('team_chat_messages')->cascadeOnDelete();
        });

        // "Delete for me": hides a message from one person only.
        Schema::create('team_chat_hides', function (Blueprint $t) {
            $t->unsignedBigInteger('message_id');
            $t->unsignedBigInteger('user_id');
            $t->timestamp('created_at')->nullable();
            $t->primary(['message_id', 'user_id']);
            $t->foreign('message_id')->references('id')->on('team_chat_messages')->cascadeOnDelete();
        });

        // Messages reported by members for the company's moderators (owner / staff managers) to review.
        Schema::create('team_chat_reports', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->index();
            $t->unsignedBigInteger('chat_id');
            $t->unsignedBigInteger('message_id');
            $t->unsignedBigInteger('reporter_id');
            $t->unsignedBigInteger('reported_user_id')->nullable();   // who sent the message
            $t->string('reason', 20);                                  // spam | harassment | inappropriate | other
            $t->string('note', 500)->nullable();
            $t->json('snapshot')->nullable();                          // message as it was reported (so it survives edit / delete)
            $t->string('status', 12)->default('open');                 // open | dismissed | actioned
            $t->string('action', 30)->nullable();                      // dismiss | delete_message | remove_sender | delete_and_remove
            $t->unsignedBigInteger('handled_by')->nullable();
            $t->timestamp('handled_at')->nullable();
            $t->timestamps();
            $t->unique(['message_id', 'reporter_id']);
            $t->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_chat_pins');
        Schema::dropIfExists('team_chat_stars');
        Schema::dropIfExists('team_chat_hides');
        Schema::dropIfExists('team_chat_receipts');
        Schema::dropIfExists('team_chat_reports');
        Schema::dropIfExists('team_chat_reactions');
        Schema::dropIfExists('team_chat_messages');
        Schema::dropIfExists('team_chat_members');
        Schema::dropIfExists('team_chats');
    }
};
