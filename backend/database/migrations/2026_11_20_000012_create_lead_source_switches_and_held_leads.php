<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-source switches for incoming lead capture, and the holding area for what arrives while a source is switched off.
 *  - lead_source_settings   one row per (company, source) that has been switched; no row = on
 *  - held_leads             a contact who would have become a lead, waiting for staff to move it into Leads (or discard it)
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('lead_source_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('source', 30);
            $table->boolean('is_enabled')->default(true);
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'source']);
        });

        Schema::create('held_leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('contact_id');
            $table->string('source', 30);                       // wa_chat | whatsapp_cloud | instagram | website | meta_ads | flow
            $table->string('origin_type', 40)->nullable();
            $table->unsignedBigInteger('origin_id')->nullable();
            $table->string('origin_label', 150)->nullable();
            $table->unsignedBigInteger('campaign_id')->nullable();
            $table->unsignedBigInteger('wa_open_campaign_id')->nullable();
            $table->unsignedBigInteger('flow_node_id')->nullable();
            $table->string('category', 120)->nullable();
            $table->string('priority', 10)->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('hits')->default(1);        // how many times this contact came in while the source was off
            $table->string('status', 12)->default('held');      // held | moved | discarded
            $table->unsignedBigInteger('lead_id')->nullable();  // the lead it became (or the open lead it turned out to already have)
            $table->string('result_note', 255)->nullable();
            $table->unsignedBigInteger('handled_by')->nullable();
            $table->timestamp('handled_at')->nullable();
            $table->timestamp('first_captured_at')->nullable();
            $table->timestamp('last_captured_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status', 'source']);
            $table->index(['company_id', 'contact_id', 'source', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('held_leads');
        Schema::dropIfExists('lead_source_settings');
    }
};
