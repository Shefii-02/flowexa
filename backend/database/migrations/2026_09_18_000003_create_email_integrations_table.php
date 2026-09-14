<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-company outbound email (SMTP) — each company connects its own mailbox/provider so
 * customer alerts, notifications and announcements go out as that company, not as us. SMTP
 * (rather than a Gmail-style OAuth flow) is the default because it works with any provider a
 * company already has — Gmail app passwords, Outlook, Zoho, SendGrid/Mailgun/SES SMTP relays —
 * without registering a separate OAuth app per provider.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_integrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connected_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('smtp_host');
            $table->unsignedSmallInteger('smtp_port')->default(587);
            $table->string('smtp_username');
            $table->text('smtp_password'); // encrypted via ApiKeyEncryption, same as CompanyApiKey
            $table->string('encryption')->default('tls'); // tls | ssl | null
            $table->string('from_email');
            $table->string('from_name')->nullable();

            $table->boolean('is_active')->default(true);
            $table->boolean('is_verified')->default(false);
            $table->timestamp('last_verified_at')->nullable();
            $table->text('last_error')->nullable();

            $table->timestamps();
            $table->unique('company_id'); // one connected mailbox per company
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_integrations');
    }
};
