<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Meta Instant Forms (lead gen) discovered on a connected page/account.
        Schema::create('meta_lead_forms', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('meta_ad_account_id')->nullable()->constrained('meta_ad_accounts')->nullOnDelete();
            $t->string('meta_form_id', 40)->unique();
            $t->string('page_id', 40)->nullable();
            $t->string('name', 200)->nullable();
            $t->string('status', 30)->nullable();     // ACTIVE | ARCHIVED | DELETED | PAUSED
            $t->json('questions')->nullable();         // [{ key, label, type }]
            $t->json('privacy_policy')->nullable();
            $t->unsignedInteger('leads_count')->default(0);
            $t->timestamp('last_synced_at')->nullable();
            $t->timestamps();
            $t->index(['company_id', 'status']);
        });

        // Every lead Meta hands us — the webhook and the manual "pull recent" both land here, and it
        // is the join between a Meta leadgen id and the CRM Contact + Lead we created from it.
        Schema::create('meta_leads', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('meta_leadgen_id', 40)->unique();
            $t->foreignId('meta_lead_form_id')->nullable()->constrained('meta_lead_forms')->nullOnDelete();
            $t->foreignId('meta_ad_id')->nullable()->constrained('meta_ads')->nullOnDelete();
            $t->foreignId('meta_campaign_id')->nullable()->constrained('meta_campaigns')->nullOnDelete();
            $t->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $t->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();
            $t->string('full_name', 200)->nullable();
            $t->string('phone', 40)->nullable();
            $t->string('email', 200)->nullable();
            $t->json('field_data')->nullable();       // the raw answers, all of them
            $t->string('platform', 20)->nullable();   // fb | ig
            $t->timestamp('meta_created_time')->nullable();
            $t->string('process_status', 20)->default('pending'); // pending | processed | failed | skipped
            $t->text('process_error')->nullable();
            $t->timestamp('processed_at')->nullable();
            $t->timestamps();
            $t->index(['company_id', 'process_status']);
            $t->index(['company_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_leads');
        Schema::dropIfExists('meta_lead_forms');
    }
};
