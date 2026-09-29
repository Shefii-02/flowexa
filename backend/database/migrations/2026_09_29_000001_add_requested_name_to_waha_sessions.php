<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The "Session Name" field on the create-session form was never persisted: the gateway session
 * name (`session_name`) is always a server-generated id (`co{companyId}-{random}`), and when the
 * user also fills in "Display Name" that value takes `display_name`'s slot instead, silently
 * dropping what the user typed. `requested_name` records it as its own column regardless of what
 * else was filled in — a plain record of the user's input, not used for gateway/session identity.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('waha_sessions', function (Blueprint $table) {
            $table->string('requested_name', 100)->nullable()->after('display_name');
        });
    }

    public function down(): void
    {
        Schema::table('waha_sessions', function (Blueprint $table) {
            $table->dropColumn('requested_name');
        });
    }
};
