<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Record which WhatsApp Chat session sent each Api Service message, so the Logs
 * / Export History views can be filtered by session. Plain string (the gateway
 * session id / name), nullable for legacy rows.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('wa_otp_logs', function (Blueprint $table) {
            $table->string('session_id', 100)->nullable()->after('config_id');
            $table->index('session_id');
        });
    }

    public function down(): void
    {
        Schema::table('wa_otp_logs', function (Blueprint $table) {
            $table->dropIndex(['session_id']);
            $table->dropColumn('session_id');
        });
    }
};
