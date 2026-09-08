<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A company-owned, named WA Cloud Api Service config. `kind` selects the
 * endpoint family (auth / utility / invoice), same as `wa_api_configs`.
 *
 * Unlike the wa-chat equivalent, every config here is backed by a real Meta
 * message template: on save the row is registered with the company's WhatsApp
 * Business account and its approval status is tracked in `template_status`.
 * Public sends go out as template messages through the Meta Cloud API.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('wa_cloud_api_configs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('kind', 20);                       // auth | utility | invoice
            $table->string('name', 100);                      // the "service" value clients send

            // Source content (shared prebuilt library or free text)
            $table->unsignedBigInteger('prebuilt_template_id')->nullable();
            $table->text('custom_content')->nullable();

            // Auth: parameters for the OTP code we generate/inject
            $table->unsignedTinyInteger('otp_length')->nullable();
            $table->unsignedSmallInteger('otp_expiry_minutes')->nullable();
            $table->unsignedTinyInteger('max_attempts')->nullable()->default(5);

            // Invoice: default caption
            $table->string('caption', 500)->nullable();

            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(100);

            // ── Meta message-template mirror ──────────────────────────────
            $table->string('wa_template_id', 64)->nullable();
            $table->string('template_name', 120);
            $table->string('template_language', 10)->default('en');
            $table->string('template_category', 20);          // AUTHENTICATION | UTILITY
            $table->string('template_status', 20)->default('draft');
            $table->text('rejection_reason')->nullable();
            $table->timestamp('template_submitted_at')->nullable();

            // Utility / invoice: compiled body with positional {{1}} placeholders
            $table->text('body_text')->nullable();
            $table->json('body_variable_names')->nullable();   // position -> original {{name}}
            $table->json('body_examples')->nullable();         // one review sample per {{n}}
            $table->string('footer_text', 60)->nullable();

            // Invoice: DOCUMENT header sample
            $table->string('header_format', 12)->nullable();   // DOCUMENT
            $table->string('header_handle', 500)->nullable();
            $table->string('header_sample_path', 500)->nullable();
            $table->string('header_sample_url', 500)->nullable();

            // Auth: Meta OTP button configuration
            $table->string('auth_delivery_method', 20)->nullable(); // copy_code | one_tap | zero_tap
            $table->json('auth_apps')->nullable();                   // up to 5 {package_name, signature_hash}
            $table->boolean('auth_add_expiry')->default(true);
            $table->unsignedTinyInteger('auth_code_expiration_minutes')->default(10);
            $table->boolean('auth_add_security_recommendation')->default(true);
            $table->boolean('auth_zero_tap_terms_accepted')->default(false);

            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('prebuilt_template_id')->references('id')->on('prebuilt_templates')->nullOnDelete();
            $table->unique(['company_id', 'kind', 'name']);
            $table->index(['company_id', 'kind', 'is_active']);
            $table->index(['company_id', 'template_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_cloud_api_configs');
    }
};
