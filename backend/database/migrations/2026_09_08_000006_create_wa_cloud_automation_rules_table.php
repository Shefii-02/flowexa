<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Automation rules for the WA Cloud (Meta Cloud API) inbox — independent of the
 * open-wa `automation_rules` table. Scoped to a WhatsApp Cloud phone number
 * (`wa_phone_number_id`, null = all of the company's numbers) rather than an
 * open-wa session.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('wa_cloud_automation_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('wa_phone_number_id')->nullable();
            $table->string('rule_type', 40); // welcome_message | out_of_office | keyword_trigger | ...
            $table->string('name', 150);
            $table->json('conditions')->nullable();
            $table->json('actions');
            $table->json('keywords')->nullable();
            $table->integer('priority')->default(0);
            $table->boolean('is_active')->default(true);
            $table->time('schedule_start')->nullable();
            $table->time('schedule_end')->nullable();
            $table->json('schedule_days')->nullable();
            $table->integer('delay_hours')->nullable();
            $table->integer('inactivity_hours')->nullable();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('wa_phone_number_id')->references('id')->on('wa_phone_numbers')->nullOnDelete();
            $table->index(['company_id', 'is_active']);
            $table->index(['company_id', 'rule_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_cloud_automation_rules');
    }
};
