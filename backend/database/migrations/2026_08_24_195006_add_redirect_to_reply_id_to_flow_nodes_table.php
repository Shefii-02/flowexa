<?php

// ════════════════════════════════════════════════════════════════════════════
// MIGRATION — add redirect_to_reply_id to flow_nodes
// When set, instead of sending this node's message, the webhook jumps to
// the node with that reply_id and sends IT instead.
// Use this for "Back" and "Main Menu" buttons.
// ════════════════════════════════════════════════════════════════════════════

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flow_nodes', function (Blueprint $table) {
            // If set, webhook ignores this node's message and redirects to the
            // node matching this reply_id (within the same builder)
            $table->string('redirect_to_reply_id', 255)->nullable()->after('reply_id');
        });
    }

    public function down(): void
    {
        Schema::table('flow_nodes', function (Blueprint $table) {
            $table->dropColumn('redirect_to_reply_id');
        });
    }
};
