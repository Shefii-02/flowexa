<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issued OTP codes for the WA Cloud OTP Service. Same shape as `wa_otp_codes`
 * plus a `config_id` link back to the `wa_cloud_api_configs` row that issued it.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('wa_cloud_otp_codes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('service_id');
            $table->unsignedBigInteger('config_id')->nullable();

            $table->string('phone', 30);
            $table->string('otp_code', 20);
            $table->string('reference_id', 100)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('domain', 255)->nullable();

            $table->enum('status', ['pending', 'verified', 'expired', 'failed'])->default('pending');
            $table->integer('attempts')->default(0);

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('service_id')->references('id')->on('wa_cloud_otp_services')->cascadeOnDelete();
            $table->foreign('config_id')->references('id')->on('wa_cloud_api_configs')->nullOnDelete();

            $table->index(['company_id', 'phone', 'status']);
            $table->index(['otp_code', 'status']);
            $table->index('config_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_cloud_otp_codes');
    }
};
