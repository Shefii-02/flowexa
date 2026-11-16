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
            $t->unsignedBigInteger('last_read_message_id')->default(0);
            $t->boolean('muted')->default(false);
            $t->timestamp('joined_at')->nullable();
            $t->timestamp('left_at')->nullable();         // set when removed / left; history stays
            $t->timestamps();
            $t->unique(['chat_id', 'user_id']);
            $t->index('user_id');
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
            $t->json('mentions')->nullable();                   // user ids
            $t->timestamp('edited_at')->nullable();
            $t->timestamp('deleted_at')->nullable();
            $t->timestamps();
            $t->index(['chat_id', 'id']);
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
    }

    public function down(): void
    {
        Schema::dropIfExists('team_chat_reactions');
        Schema::dropIfExists('team_chat_messages');
        Schema::dropIfExists('team_chat_members');
        Schema::dropIfExists('team_chats');
    }
};
