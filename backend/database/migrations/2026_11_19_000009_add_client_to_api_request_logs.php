<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('api_request_logs', function (Blueprint $table) {
            $table->string('client', 10)->nullable()->after('user_agent');   // portal (web) | app (mobile)
            $table->index(['company_id', 'client', 'created_at']);
        });

        // Backfill: the Flutter app talks through Dart's HTTP stack; everything else is the web portal.
        DB::table('api_request_logs')->whereNull('client')->where('user_agent', 'like', '%Dart/%')->update(['client' => 'app']);
        DB::table('api_request_logs')->whereNull('client')->update(['client' => 'portal']);
    }

    public function down(): void
    {
        Schema::table('api_request_logs', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'client', 'created_at']);
            $table->dropColumn('client');
        });
    }
};
