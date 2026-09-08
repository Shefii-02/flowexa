<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Activity log for the WA Cloud OTP Service — one row per send / verify /
 * utility / invoice attempt. `action` is a plain string from day one (the
 * wa-chat equivalent started life as an ENUM and needed a later widening
 * migration — don't repeat that).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('wa_cloud_otp_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('service_id');
            $table->unsignedBigInteger('config_id')->nullable();

            $table->string('phone', 30);
            $table->string('action', 30); // sent | verified | failed | resend | utility | invoice_share
            $table->string('wa_message_id', 120)->nullable();
            $table->text('error')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('domain', 255)->nullable();
            $table->integer('response_ms')->default(0);
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('service_id')->references('id')->on('wa_cloud_otp_services')->cascadeOnDelete();
            $table->foreign('config_id')->references('id')->on('wa_cloud_api_configs')->nullOnDelete();

            $table->index(['company_id', 'created_at']);
            $table->index('config_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_cloud_otp_logs');
    }
};
