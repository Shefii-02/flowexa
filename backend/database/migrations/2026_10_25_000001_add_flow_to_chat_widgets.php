<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('chat_widgets', function (Blueprint $t) {
            $t->unsignedBigInteger('flow_builder_id')->nullable()->after('wa_session_id');
        });
        Schema::table('widget_conversations', function (Blueprint $t) {
            $t->unsignedBigInteger('flow_node_id')->nullable()->after('collected');
        });
    }

    public function down(): void
    {
        Schema::table('chat_widgets', fn (Blueprint $t) => $t->dropColumn('flow_builder_id'));
        Schema::table('widget_conversations', fn (Blueprint $t) => $t->dropColumn('flow_node_id'));
    }
};
