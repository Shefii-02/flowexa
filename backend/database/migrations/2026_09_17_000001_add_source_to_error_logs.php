<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Distinguishes which system an error_logs row came from — Laravel's own report()
 * hook, a React frontend crash reported via POST /frontend-errors, or (proxied live,
 * never actually stored here) backend-node's own audit log. Existing rows all predate
 * this column and are Laravel's own exceptions, so they backfill to 'laravel'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('error_logs', function (Blueprint $table) {
            $table->string('source', 20)->default('laravel')->after('company_id');
        });

        DB::table('error_logs')->update(['source' => 'laravel']);

        Schema::table('error_logs', function (Blueprint $table) {
            $table->index(['source', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('error_logs', function (Blueprint $table) {
            $table->dropIndex(['source', 'created_at']);
            $table->dropColumn('source');
        });
    }
};
