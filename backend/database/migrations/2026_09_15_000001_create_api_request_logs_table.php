<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_request_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('actor_role', 40)->nullable();
            $table->string('method', 10);
            $table->string('path', 255);
            $table->string('route_name', 150)->nullable();
            $table->unsignedSmallInteger('status_code');
            $table->unsignedInteger('duration_ms');
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->text('request_summary')->nullable();
            $table->text('response_summary')->nullable();
            $table->boolean('is_error')->default(false);
            $table->timestamp('created_at')->nullable();

            $table->index(['company_id', 'created_at']);
            $table->index(['created_at']);
            $table->index(['is_error']);
            $table->index(['method']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_request_logs');
    }
};
