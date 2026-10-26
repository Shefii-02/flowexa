<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('chat_widgets', function (Blueprint $t) {
            $t->boolean('live_chat_enabled')->default(false);
            $t->unsignedSmallInteger('live_wait_seconds')->default(90); // how long a visitor waits before "we'll call you back"
        });
        Schema::table('widget_conversations', function (Blueprint $t) {
            // null (bot only) | waiting | live | missed (nobody accepted in time) | ended
            $t->string('live_status', 10)->nullable()->index();
            $t->timestamp('live_requested_at')->nullable();
            $t->timestamp('live_accepted_at')->nullable();
            $t->unsignedBigInteger('assigned_user_id')->nullable();
            $t->string('staff_name', 120)->nullable();
            $t->timestamp('callback_confirmed_at')->nullable(); // visitor confirmed name/number after a missed request
        });
    }

    public function down(): void
    {
        Schema::table('chat_widgets', fn (Blueprint $t) => $t->dropColumn(['live_chat_enabled', 'live_wait_seconds']));
        Schema::table('widget_conversations', fn (Blueprint $t) => $t->dropColumn([
            'live_status', 'live_requested_at', 'live_accepted_at', 'assigned_user_id', 'staff_name', 'callback_confirmed_at',
        ]));
    }
};
