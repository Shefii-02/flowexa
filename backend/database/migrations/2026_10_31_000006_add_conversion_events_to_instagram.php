<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instagram_accounts', function (Blueprint $t) {
            // Opt-in: report this account's lead / sale milestones back to Meta (instagram_manage_events).
            $t->boolean('send_conversion_events')->default(false);
            $t->json('conversion_events')->nullable();          // which of: lead, purchase
            $t->string('conversion_dataset_id', 40)->nullable(); // Meta dataset for this IG account, created on first send
        });

        Schema::create('instagram_conversion_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('instagram_account_id')->constrained('instagram_accounts')->cascadeOnDelete();
            $t->string('event_id', 60);                          // lead-12 / purchase-7 — one event per milestone, never twice
            $t->string('event_name', 30);                        // LeadSubmitted | Purchase
            $t->unsignedBigInteger('lead_id')->nullable();
            $t->unsignedBigInteger('deal_id')->nullable();
            $t->decimal('value', 14, 2)->nullable();
            $t->string('currency', 3)->nullable();
            $t->string('status', 10);                            // sent | failed
            $t->text('error')->nullable();
            $t->timestamp('event_time');
            $t->timestamps();

            $t->unique(['instagram_account_id', 'event_id'], 'ig_conv_event_unique');
            $t->index(['company_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instagram_conversion_events');
        Schema::table('instagram_accounts', function (Blueprint $t) {
            $t->dropColumn(['send_conversion_events', 'conversion_events', 'conversion_dataset_id']);
        });
    }
};
