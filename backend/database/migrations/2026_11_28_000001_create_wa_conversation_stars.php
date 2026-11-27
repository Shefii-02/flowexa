<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // A personal star on a WA Cloud chat: each user keeps their own set (others can't see or change it).
        Schema::create('wa_conversation_stars', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('conversation_id');
            $t->timestamp('created_at')->useCurrent();
            $t->unique(['user_id', 'conversation_id']);
            $t->index(['company_id', 'user_id']);
            $t->foreign('conversation_id')->references('id')->on('wa_conversations')->cascadeOnDelete();
        });
    }

    public function down(): void { Schema::dropIfExists('wa_conversation_stars'); }
};
