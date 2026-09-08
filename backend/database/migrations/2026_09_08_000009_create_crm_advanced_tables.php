<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Advanced CRM" — the deal pipeline, follow-up tasks and saved contact
 * segments that most modern CRM providers offer. Company-scoped, opt-in.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('crm_deals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('contact_id')->nullable();
            $table->unsignedBigInteger('owner_id')->nullable();     // users.id
            $table->string('title', 200);
            $table->decimal('value', 14, 2)->default(0);
            $table->string('currency', 3)->default('INR');
            $table->string('stage', 30)->default('new');            // new|qualified|proposal|negotiation|won|lost
            $table->string('status', 12)->default('open');          // open|won|lost
            $table->date('expected_close_date')->nullable();
            $table->string('source', 60)->nullable();
            $table->string('lost_reason', 200)->nullable();
            $table->text('notes')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('contact_id')->references('id')->on('contacts')->nullOnDelete();
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'stage']);
        });

        Schema::create('crm_tasks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('contact_id')->nullable();
            $table->unsignedBigInteger('deal_id')->nullable();
            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->string('type', 20)->default('todo');            // todo|call|email|whatsapp|meeting
            $table->string('priority', 10)->default('medium');      // low|medium|high
            $table->string('status', 12)->default('open');          // open|done|cancelled
            $table->timestamp('due_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('contact_id')->references('id')->on('contacts')->nullOnDelete();
            $table->foreign('deal_id')->references('id')->on('crm_deals')->nullOnDelete();
            $table->index(['company_id', 'status', 'due_at']);
            $table->index(['company_id', 'assigned_to']);
        });

        Schema::create('crm_segments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('name', 120);
            $table->string('description', 255)->nullable();
            $table->json('filters');   // { stage, lead_stage, score_min, label_id, source, opted_in }
            $table->boolean('is_shared')->default(true);
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->index(['company_id', 'is_shared']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_segments');
        Schema::dropIfExists('crm_tasks');
        Schema::dropIfExists('crm_deals');
    }
};
