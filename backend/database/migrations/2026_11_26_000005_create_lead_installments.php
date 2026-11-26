<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Installment payments for a lead's sale (e.g. a 2999 course paid over several months), who follows them up, and the HR rules for how they count
// toward sales targets. Payments are the source of truth; each one can create a listing_sale (so targets + incentives work as before).
return new class extends Migration {
    public function up(): void
    {
        Schema::create('installment_settings', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->unique();
            $t->string('target_basis', 10)->default('collected');       // collected = each payment counts when paid | full = whole value at enrolment | first = first installment only
            $t->boolean('category_follow_basis')->default(true);          // lead-category targets use the same basis (off = they keep using the full lead value)
            $t->string('late_fee_mode', 10)->default('none');            // none | fixed | percent
            $t->decimal('late_fee_value', 10, 2)->default(0);
            $t->unsignedSmallInteger('late_fee_grace_days')->default(0);
            $t->boolean('late_fee_counts_for_target')->default(false);   // a collected late fee also counts toward the target
            $t->string('followup_mode', 12)->default('owner_hr');         // owner | owner_hr | collector
            $t->unsignedBigInteger('collector_user_id')->nullable();
            $t->timestamps();
        });

        Schema::create('lead_installment_plans', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('lead_id');
            $t->decimal('total_amount', 12, 2);
            $t->string('status', 12)->default('active');                 // active | completed | cancelled
            $t->unsignedBigInteger('created_by')->nullable();
            $t->string('note', 255)->nullable();
            $t->timestamps();
            $t->index(['company_id', 'lead_id']);
        });

        Schema::create('lead_installments', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('plan_id');
            $t->unsignedBigInteger('lead_id');
            $t->unsignedSmallInteger('seq');
            $t->date('due_date');
            $t->decimal('amount', 12, 2);
            $t->string('status', 10)->default('pending');                // pending | partial | paid | waived
            $t->decimal('late_fee', 10, 2)->default(0);
            $t->unsignedBigInteger('assigned_to')->nullable();           // who follows this one up (null = the lead's owner)
            $t->date('promised_on')->nullable();
            $t->timestamp('last_followup_at')->nullable();
            $t->string('waived_reason', 255)->nullable();
            $t->timestamps();
            $t->index(['company_id', 'status', 'due_date']);
            $t->index(['plan_id']);
            $t->foreign('plan_id')->references('id')->on('lead_installment_plans')->cascadeOnDelete();
        });

        Schema::create('lead_installment_payments', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('installment_id');
            $t->unsignedBigInteger('lead_id');
            $t->decimal('amount', 12, 2);                                // toward the installment itself
            $t->decimal('late_fee_amount', 10, 2)->default(0);           // collected on top as the late fee
            $t->date('paid_on');
            $t->string('method', 20)->nullable();
            $t->string('reference', 100)->nullable();
            $t->unsignedBigInteger('recorded_by')->nullable();
            $t->unsignedBigInteger('sale_id')->nullable();               // the listing_sale this payment created, if the target basis counted it
            $t->timestamp('reversed_at')->nullable();
            $t->unsignedBigInteger('reversed_by')->nullable();
            $t->string('reverse_reason', 255)->nullable();
            $t->timestamps();
            $t->index(['installment_id']);
            $t->foreign('installment_id')->references('id')->on('lead_installments')->cascadeOnDelete();
        });

        Schema::create('lead_installment_logs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('lead_id');
            $t->unsignedBigInteger('installment_id')->nullable();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('action', 30);                                    // plan_created | edited | paid | reversed | waived | followup | assigned | late_fee
            $t->json('details')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['company_id', 'lead_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_installment_logs');
        Schema::dropIfExists('lead_installment_payments');
        Schema::dropIfExists('lead_installments');
        Schema::dropIfExists('lead_installment_plans');
        Schema::dropIfExists('installment_settings');
    }
};
