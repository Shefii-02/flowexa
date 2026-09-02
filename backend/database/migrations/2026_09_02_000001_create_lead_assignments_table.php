<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('lead_assignments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('contact_id');
            $table->unsignedBigInteger('staff_id')->nullable();
            $table->unsignedBigInteger('ai_agent_session_id')->nullable();
            $table->unsignedBigInteger('campaign_id')->nullable();
            $table->enum('source_type', ['wa_chat','meta_api','campaign','organic','flow_builder','manual'])->default('organic');
            $table->string('source_ref', 255)->nullable();
            $table->enum('status', ['pending','notified','accepted','assigned','ai_handling','ai_offered','transferred','completed','dropped'])->default('pending');
            $table->enum('assignment_type', ['auto','manual','notification'])->default('auto');
            $table->tinyInteger('priority')->default(5);
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('first_reply_at')->nullable();
            $table->integer('response_sla_minutes')->default(30);
            $table->boolean('sla_breached')->default(false);
            $table->timestamp('sla_breached_at')->nullable();
            $table->timestamp('ai_takeover_at')->nullable();
            $table->timestamp('ai_offered_at')->nullable();
            $table->timestamp('staff_confirmed_at')->nullable();
            $table->text('transfer_reason')->nullable();
            $table->unsignedBigInteger('transferred_from')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status', 'staff_id']);
            $table->index(['company_id', 'contact_id']);
            $table->index(['company_id', 'campaign_id']);
            $table->index(['staff_id', 'status']);

            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('contact_id')->references('id')->on('contacts')->cascadeOnDelete();
            $table->foreign('staff_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('campaign_id')->references('id')->on('campaigns')->nullOnDelete();
            $table->foreign('transferred_from')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_assignments');
    }
};
