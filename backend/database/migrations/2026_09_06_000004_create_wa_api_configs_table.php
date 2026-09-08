<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A company-owned, named configuration for one of the public Api Service
 * endpoints. `kind` selects the endpoint family:
 *   - auth    : /api/v1/otp/{send,verify,resend}
 *   - utility : /api/v1/api-service/utility-send
 *   - invoice : /api/v1/api-service/invoice-share
 *
 * The client names the config it wants via a `service` field in the request
 * body; `name` is that key (unique per company + kind). Auth configs carry the
 * OTP length / expiry / attempt cap; utility configs carry either a library
 * template or free `custom_content`; invoice configs carry file defaults.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('wa_api_configs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('kind', 20);
            $table->string('name', 100);

            $table->unsignedBigInteger('prebuilt_template_id')->nullable();
            $table->text('custom_content')->nullable();

            $table->unsignedTinyInteger('otp_length')->nullable();
            $table->unsignedSmallInteger('otp_expiry_minutes')->nullable();
            $table->unsignedTinyInteger('max_attempts')->nullable()->default(5);

            $table->string('session_id', 100)->nullable();

            $table->string('file_url', 1000)->nullable();
            $table->string('filename', 200)->nullable();
            $table->string('caption', 500)->nullable();

            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(100);
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('prebuilt_template_id')->references('id')->on('prebuilt_templates')->nullOnDelete();
            $table->unique(['company_id', 'kind', 'name']);
            $table->index(['company_id', 'kind', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_api_configs');
    }
};
