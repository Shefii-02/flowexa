<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `waha_sessions` only gets a row when a session is created through Laravel's
 * WahaSessionController. Sessions created straight on the open-wa gateway never
 * land here, which is why the table reads empty and has no creation time. The
 * Api Service now mirrors the gateway's session list into this table on each
 * fetch, so add the fields the gateway carries:
 *   - gateway_created_at : when the session was created on the gateway
 *   - session_token      : a per-session key, when the gateway issues one
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('waha_sessions', function (Blueprint $table) {
            $table->timestamp('gateway_created_at')->nullable()->after('last_seen_at');
            $table->string('session_token', 255)->nullable()->after('gateway_created_at');
        });
    }

    public function down(): void
    {
        Schema::table('waha_sessions', function (Blueprint $table) {
            $table->dropColumn(['gateway_created_at', 'session_token']);
        });
    }
};
