<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('chat_widgets', function (Blueprint $t) {
            $t->unsignedTinyInteger('live_max_per_staff')->default(5); // live chats one staff member may handle at once
        });

        Schema::table('widget_messages', function (Blueprint $t) {
            $t->string('type', 10)->default('text');            // text | image | audio | file
            $t->string('attachment_path', 300)->nullable();      // on the public disk
            $t->string('attachment_name', 200)->nullable();
            $t->string('attachment_mime', 100)->nullable();
            $t->unsignedInteger('attachment_size')->nullable();  // bytes
            $t->unsignedBigInteger('reply_to_id')->nullable();   // quoted message
        });

        Schema::create('widget_chat_staff', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete()->unique();
            $t->boolean('muted')->default(false);                // no new-chat alerts while true
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('widget_chat_staff');
        Schema::table('widget_messages', fn (Blueprint $t) => $t->dropColumn([
            'type', 'attachment_path', 'attachment_name', 'attachment_mime', 'attachment_size', 'reply_to_id',
        ]));
        Schema::table('chat_widgets', fn (Blueprint $t) => $t->dropColumn('live_max_per_staff'));
    }
};
