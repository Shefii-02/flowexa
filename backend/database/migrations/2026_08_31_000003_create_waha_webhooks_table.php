<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('waha_webhooks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('session_id')->nullable();
            $table->string('name', 150);
            $table->string('url', 500);
            $table->json('events')->nullable();
            $table->string('secret', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_triggered_at')->nullable();
            $table->integer('last_status_code')->nullable();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->foreign('session_id')->references('id')->on('waha_sessions')->onDelete('set null');
            $table->index('company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waha_webhooks');
    }
};
