<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Company-level settings for the WA Cloud "OTP Service" — the Meta-Cloud-API
 * flavour of the Api Service. Parallel to `wa_otp_services` (which is the
 * open-wa / wa-chat flavour) but deliberately without the open-wa columns
 * (session_id / delivery_channel / otp tuning): WA Cloud always sends through
 * Meta using the company's `wa_phone_id`, and all OTP tuning lives per-config
 * on `wa_cloud_api_configs`.
 *
 * The public API authenticates callers by `api_token` alone (Bearer header).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('wa_cloud_otp_services', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->unique();
            $table->string('api_token', 255)->nullable()->unique();
            $table->timestamp('api_token_created_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('allowed_domains')->nullable();
            $table->json('allowed_packages')->nullable();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_cloud_otp_services');
    }
};
