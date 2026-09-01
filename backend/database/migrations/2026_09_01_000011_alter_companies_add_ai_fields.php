<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // FK to active key per provider — nullable, added after company_api_keys exists
            if (!Schema::hasColumn('companies', 'openai_key_id')) {
                $table->foreignId('openai_key_id')
                    ->nullable()
                    ->constrained('company_api_keys')
                    ->nullOnDelete();
            }
            if (!Schema::hasColumn('companies', 'anthropic_key_id')) {
                $table->foreignId('anthropic_key_id')
                    ->nullable()
                    ->constrained('company_api_keys')
                    ->nullOnDelete();
            }
            if (!Schema::hasColumn('companies', 'ai_provider')) {
                $table->enum('ai_provider', ['anthropic', 'openai', 'google_ai'])
                    ->default('anthropic');
            }
            if (!Schema::hasColumn('companies', 'ai_model')) {
                $table->string('ai_model', 100)->default('claude-haiku-4-5');
            }
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropForeignIdFor(\App\Models\Company::class, 'openai_key_id');
            $table->dropForeignIdFor(\App\Models\Company::class, 'anthropic_key_id');
            $table->dropColumn(['openai_key_id', 'anthropic_key_id', 'ai_provider', 'ai_model']);
        });
    }
};
