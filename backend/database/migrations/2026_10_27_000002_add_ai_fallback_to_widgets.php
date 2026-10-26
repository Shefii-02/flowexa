<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('chat_widgets', function (Blueprint $t) {
            // When no staff accepts a live request, let the AI agent (knowledge base) keep helping. Needs an AI key.
            $t->boolean('ai_fallback_enabled')->default(true)->after('live_wait_seconds');
        });
        Schema::table('widget_conversations', function (Blueprint $t) {
            $t->boolean('ai_active')->default(false)->after('live_status');
        });
    }

    public function down(): void
    {
        Schema::table('widget_conversations', fn (Blueprint $t) => $t->dropColumn('ai_active'));
        Schema::table('chat_widgets', fn (Blueprint $t) => $t->dropColumn('ai_fallback_enabled'));
    }
};
