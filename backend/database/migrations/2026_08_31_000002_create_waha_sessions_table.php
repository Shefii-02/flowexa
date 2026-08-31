<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('waha_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('session_name', 100)->unique();
            $table->string('display_name', 150)->nullable();
            $table->string('phone', 30)->nullable();
            $table->enum('status', ['stopped', 'starting', 'qr', 'connected', 'disconnected'])->default('stopped');
            $table->string('webhook_url', 500)->nullable();
            $table->string('engine', 30)->default('WEBJS');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->index('company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waha_sessions');
    }
};
