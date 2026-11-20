<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Clear chat" and "Delete for me" — personal, nobody else is affected.
 *   cleared_before_id  messages up to this id are hidden from THIS member (history before it is gone for them; later messages show)
 *   hidden             the conversation is removed from THIS member's list; it comes back by itself when a newer message arrives
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('team_chat_members', function (Blueprint $t) {
            $t->unsignedBigInteger('cleared_before_id')->default(0)->after('last_read_message_id');
            $t->boolean('hidden')->default(false)->after('cleared_before_id');
        });
    }

    public function down(): void
    {
        Schema::table('team_chat_members', function (Blueprint $t) {
            $t->dropColumn(['cleared_before_id', 'hidden']);
        });
    }
};
