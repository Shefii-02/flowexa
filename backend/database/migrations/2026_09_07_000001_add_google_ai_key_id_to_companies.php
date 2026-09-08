<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (!Schema::hasColumn('companies', 'google_ai_key_id')) {
                $table->foreignId('google_ai_key_id')
                    ->nullable()
                    ->after('anthropic_key_id')
                    ->constrained('company_api_keys')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (Schema::hasColumn('companies', 'google_ai_key_id')) {
                $table->dropConstrainedForeignId('google_ai_key_id');
            }
        });
    }
};
