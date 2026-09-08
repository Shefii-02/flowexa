<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HR payroll & incentives:
 *  - hr_incentive_rules  : per service / course / product incentive %
 *  - hr_incentives       : the earned-incentive ledger (auto from won deals, or manual)
 *  - hr_payroll_runs     : a monthly payroll batch (draft → released)
 *  - hr_payroll_items    : one staff member's computed pay in a run, custom-editable
 *  + a few hr_settings columns for the deduction rules
 *  + crm_deals.incentive_rule_id
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('hr_settings', function (Blueprint $table) {
            $table->decimal('late_penalty_amount', 10, 2)->default(0)->after('overtime_multiplier'); // per late day
            $table->unsignedSmallInteger('payroll_working_days')->default(26)->after('late_penalty_amount');
            $table->boolean('deduct_unpaid_leave')->default(true)->after('payroll_working_days');
            $table->boolean('deduct_absent_days')->default(true)->after('deduct_unpaid_leave');
        });

        Schema::table('crm_deals', function (Blueprint $table) {
            $table->unsignedBigInteger('incentive_rule_id')->nullable()->after('owner_id');
        });

        Schema::create('hr_incentive_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name', 120);                       // the service / course / product
            $table->string('category', 20)->default('service'); // service | course | product | other
            $table->string('kind', 12)->default('percentage');  // percentage | fixed
            $table->decimal('percent', 6, 3)->default(0);        // % of the sale value
            $table->decimal('fixed_amount', 12, 2)->default(0);
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->index(['company_id', 'is_active']);
        });

        Schema::create('hr_incentives', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('incentive_rule_id')->nullable();
            $table->string('source_type', 12)->default('manual'); // deal | lead | manual
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('title', 160);
            $table->decimal('base_amount', 14, 2)->default(0);    // the sale value
            $table->decimal('amount', 12, 2)->default(0);         // computed incentive
            $table->date('earned_on');
            $table->string('status', 12)->default('pending');     // pending | approved | paid
            $table->unsignedBigInteger('payroll_item_id')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('incentive_rule_id')->references('id')->on('hr_incentive_rules')->nullOnDelete();
            $table->index(['company_id', 'user_id', 'status']);
            $table->index(['company_id', 'earned_on']);
        });

        Schema::create('hr_payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('period', 7);                 // YYYY-MM
            $table->string('status', 12)->default('draft'); // draft | released
            $table->json('totals')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('generated_by')->nullable();
            $table->unsignedBigInteger('released_by')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->unique(['company_id', 'period']);
        });

        Schema::create('hr_payroll_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('payroll_run_id');
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('user_id');

            $table->unsignedSmallInteger('present_days')->default(0);
            $table->decimal('paid_leave_days', 5, 1)->default(0);
            $table->decimal('unpaid_leave_days', 5, 1)->default(0);
            $table->unsignedSmallInteger('absent_days')->default(0);
            $table->unsignedSmallInteger('late_days')->default(0);
            $table->decimal('worked_hours', 8, 2)->default(0);
            $table->decimal('overtime_hours', 8, 2)->default(0);

            $table->decimal('base_pay', 12, 2)->default(0);
            $table->decimal('overtime_pay', 12, 2)->default(0);
            $table->decimal('incentive_pay', 12, 2)->default(0);
            $table->decimal('allowances', 12, 2)->default(0);
            $table->decimal('deductions', 12, 2)->default(0);
            $table->decimal('gross_pay', 12, 2)->default(0);
            $table->decimal('net_pay', 12, 2)->default(0);

            $table->json('adjustments')->nullable();  // [{label, amount}] custom lines (+/-)
            $table->json('computed')->nullable();     // raw snapshot for transparency
            $table->text('note')->nullable();
            $table->string('status', 12)->default('draft'); // draft | approved
            $table->timestamps();

            $table->foreign('payroll_run_id')->references('id')->on('hr_payroll_runs')->cascadeOnDelete();
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['payroll_run_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_payroll_items');
        Schema::dropIfExists('hr_payroll_runs');
        Schema::dropIfExists('hr_incentives');
        Schema::dropIfExists('hr_incentive_rules');
        Schema::table('crm_deals', fn (Blueprint $t) => $t->dropColumn('incentive_rule_id'));
        Schema::table('hr_settings', fn (Blueprint $t) => $t->dropColumn([
            'late_penalty_amount', 'payroll_working_days', 'deduct_unpaid_leave', 'deduct_absent_days',
        ]));
    }
};
