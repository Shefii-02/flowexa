<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('company_api_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->enum('provider', ['openai', 'anthropic', 'google_ai', 'custom']);
            $table->string('key_label', 100);
            $table->text('api_key');                      // AES-256 encrypted via APP_KEY
            $table->string('api_key_hint', 20)->nullable(); // last 6 chars e.g. "...abc123"
            $table->boolean('is_active')->default(true);
            $table->boolean('is_verified')->default(false);
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->unsignedInteger('usage_count')->default(0);
            $table->decimal('monthly_limit_usd', 10, 2)->nullable();
            $table->decimal('monthly_used_usd', 10, 2)->default(0);
            $table->json('meta')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'provider', 'key_label']);
            $table->index(['company_id', 'provider', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_api_keys');
    }
};
