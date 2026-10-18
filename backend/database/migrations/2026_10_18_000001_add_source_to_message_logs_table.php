<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_logs', function (Blueprint $table) {
            // Where the message came from: webhook | test | campaign | api | automation | inbox | otp
            $table->string('source', 20)->nullable()->after('channel');
            // Campaign id / API service config id etc. — whatever `source` points at.
            $table->string('reference_id', 50)->nullable()->after('source');
            $table->string('failure_reason', 500)->nullable()->after('status');

            $table->index(['company_id', 'channel', 'source'], 'message_logs_company_channel_source_idx');
        });
    }

    public function down(): void
    {
        Schema::table('message_logs', function (Blueprint $table) {
            $table->dropIndex('message_logs_company_channel_source_idx');
            $table->dropColumn(['source', 'reference_id', 'failure_reason']);
        });
    }
};
