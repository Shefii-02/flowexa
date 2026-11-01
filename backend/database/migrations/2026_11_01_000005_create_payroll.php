<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acc_employees', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->index();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('code', 20)->nullable();
            $t->string('name');
            $t->string('designation', 100)->nullable();
            $t->date('joined_on');
            $t->date('left_on')->nullable();
            $t->string('status', 8)->default('active');                 // active | left
            $t->decimal('basic', 15, 2);                                // monthly basic wage
            $t->decimal('allowance', 15, 2)->default(0);                // monthly allowances
            $t->decimal('annual_ticket', 15, 2)->default(0);            // yearly home-leave ticket entitlement
            $t->decimal('leave_days_per_year', 5, 2)->default(30);      // paid leave earned per year
            $t->decimal('eos_days_per_year', 5, 2)->default(21);        // end-of-service: days of basic per year of service
            $t->decimal('leave_taken_days', 6, 2)->default(0);          // paid leave already taken
            $t->decimal('ticket_used', 15, 2)->default(0);              // ticket value already paid out
            $t->date('settled_on')->nullable();
            $t->decimal('settlement_amount', 15, 2)->nullable();
            $t->unsignedBigInteger('settlement_voucher_id')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
        });

        Schema::create('acc_payroll_runs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->index();
            $t->char('period', 7);                                      // YYYY-MM
            $t->string('status', 8)->default('draft');                  // draft | posted | paid
            $t->decimal('total_gross', 15, 2)->default(0);
            $t->decimal('total_deductions', 15, 2)->default(0);
            $t->decimal('total_net', 15, 2)->default(0);
            $t->decimal('total_provisions', 15, 2)->default(0);
            $t->unsignedBigInteger('voucher_id')->nullable();           // accrual journal
            $t->unsignedBigInteger('payment_voucher_id')->nullable();
            $t->date('paid_on')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'period']);
        });

        Schema::create('acc_payroll_lines', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('run_id')->index();
            $t->unsignedBigInteger('employee_id')->index();
            $t->decimal('basic', 15, 2)->default(0);                    // pro-rated for part months
            $t->decimal('allowance', 15, 2)->default(0);
            $t->decimal('commission', 15, 2)->default(0);
            $t->decimal('unpaid_days', 4, 1)->default(0);
            $t->decimal('unpaid_deduction', 15, 2)->default(0);
            $t->decimal('other_deductions', 15, 2)->default(0);
            $t->decimal('net', 15, 2)->default(0);
            $t->decimal('leave_accrual', 15, 2)->default(0);
            $t->decimal('ticket_accrual', 15, 2)->default(0);
            $t->decimal('eos_accrual', 15, 2)->default(0);
            $t->string('notes')->nullable();
            $t->unique(['run_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acc_payroll_lines');
        Schema::dropIfExists('acc_payroll_runs');
        Schema::dropIfExists('acc_employees');
    }
};
