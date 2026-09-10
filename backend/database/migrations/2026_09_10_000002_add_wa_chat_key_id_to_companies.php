<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The node gateway's UUID for a company's API key (Company.wa_chat_token).
 * Needed to PATCH the key's `allowedSessions` allowlist as the company adds or
 * removes WhatsApp sessions — the core of the per-company (SaaS) session scope.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (! Schema::hasColumn('companies', 'wa_chat_key_id')) {
                $table->string('wa_chat_key_id', 64)->nullable()->after('wa_chat_token_expires_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (Schema::hasColumn('companies', 'wa_chat_key_id')) {
                $table->dropColumn('wa_chat_key_id');
            }
        });
    }
};
