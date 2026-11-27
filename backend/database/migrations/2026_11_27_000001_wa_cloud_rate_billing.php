<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $t) {
            $t->json('wa_cloud_rates')->nullable();                 // per-category ₹ (pre-GST) overrides set by the superadmin; null / missing key = Meta default
            $t->decimal('wa_cloud_gst_percent', 5, 2)->nullable();  // null = default GST
        });

        // One row per delivered, billable WA Cloud message. The unique key makes a repeated delivery webhook harmless.
        Schema::create('wa_cloud_charges', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('wa_message_id', 191);
            $t->unsignedBigInteger('campaign_id')->nullable();
            $t->string('source', 12)->default('campaign');           // campaign | inbox
            $t->string('category', 30);
            $t->decimal('rate_inr', 10, 4)->default(0);              // before GST
            $t->decimal('gst_percent', 5, 2)->default(0);
            $t->decimal('total_inr', 10, 4)->default(0);             // rate + GST
            $t->unsignedSmallInteger('credits')->default(0);
            $t->string('status', 10)->default('charging');           // charging | charged | free | unbilled (wallet empty)
            $t->unsignedBigInteger('wallet_transaction_id')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'wa_message_id']);
            $t->index(['company_id', 'created_at']);
            $t->index('campaign_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_cloud_charges');
        Schema::table('companies', fn (Blueprint $t) => $t->dropColumn(['wa_cloud_rates', 'wa_cloud_gst_percent']));
    }
};
