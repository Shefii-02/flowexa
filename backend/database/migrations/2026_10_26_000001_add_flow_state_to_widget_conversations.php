<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('widget_conversations', function (Blueprint $t) {
            // null | resolved (asked "Was your issue solved?") | issue (waiting for the visitor to describe it)
            $t->string('flow_state', 16)->nullable()->after('flow_node_id');
        });
    }

    public function down(): void
    {
        Schema::table('widget_conversations', fn (Blueprint $t) => $t->dropColumn('flow_state'));
    }
};
