<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_logs', function (Blueprint $table) {
            $table->timestamp('sent_at')->nullable()->after('failure_reason');
            $table->timestamp('retry_after')->nullable()->after('sent_at');           // failed campaign sends: when a retry is allowed
            $table->string('reference_name', 150)->nullable()->after('reference_id'); // e.g. campaign name
            $table->string('category', 20)->nullable()->after('type');                // marketing | utility | authentication | service

            $table->index(['company_id', 'channel', 'created_at'], 'message_logs_stats_idx');
        });
    }

    public function down(): void
    {
        Schema::table('message_logs', function (Blueprint $table) {
            $table->dropIndex('message_logs_stats_idx');
            $table->dropColumn(['sent_at', 'retry_after', 'reference_name', 'category']);
        });
    }
};
