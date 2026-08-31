<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (!Schema::hasColumn('companies', 'waha_enabled'))
                $table->boolean('waha_enabled')->default(false)->after('settings');
            if (!Schema::hasColumn('companies', 'waha_max_sessions'))
                $table->integer('waha_max_sessions')->default(1)->after('waha_enabled');
            if (!Schema::hasColumn('companies', 'waha_max_webhooks'))
                $table->integer('waha_max_webhooks')->default(3)->after('waha_max_sessions');
            if (!Schema::hasColumn('companies', 'waha_media_limit_mb'))
                $table->integer('waha_media_limit_mb')->default(500)->after('waha_max_webhooks');
            if (!Schema::hasColumn('companies', 'waha_media_used_mb'))
                $table->decimal('waha_media_used_mb', 10, 2)->default(0)->after('waha_media_limit_mb');
            if (!Schema::hasColumn('companies', 'wa_auth_enabled'))
                $table->boolean('wa_auth_enabled')->default(false)->after('waha_media_used_mb');
            if (!Schema::hasColumn('companies', 'wa_chat_token'))
                $table->string('wa_chat_token', 500)->nullable()->after('wa_auth_enabled');
            if (!Schema::hasColumn('companies', 'wa_chat_token_expires_at'))
                $table->timestamp('wa_chat_token_expires_at')->nullable()->after('wa_chat_token');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn([
                'waha_enabled', 'waha_max_sessions', 'waha_max_webhooks',
                'waha_media_limit_mb', 'waha_media_used_mb', 'wa_auth_enabled',
                'wa_chat_token', 'wa_chat_token_expires_at',
            ]);
        });
    }
};
