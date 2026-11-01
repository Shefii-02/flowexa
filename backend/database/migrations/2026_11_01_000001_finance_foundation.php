<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Chart-of-accounts code (1000s assets … 5000s expenses) and customer credit terms live on the ledger.
        Schema::table('acc_ledgers', function (Blueprint $t) {
            $t->string('code', 8)->nullable()->after('company_id');
            $t->decimal('credit_limit', 15, 2)->default(0)->after('pan');        // 0 = no limit
            $t->unsignedSmallInteger('credit_days')->default(0)->after('credit_limit');
            $t->boolean('credit_hold')->default(false)->after('credit_days');
            $t->unique(['company_id', 'code']);
        });

        Schema::table('acc_settings', function (Blueprint $t) {
            $t->unsignedSmallInteger('chart_version')->default(0);
            $t->date('locked_until')->nullable();                               // no posting on/before this date
            $t->decimal('petty_cash_limit', 15, 2)->default(0);                 // imprest float
            $t->unsignedSmallInteger('quotation_validity_days')->default(15);
        });

        Schema::table('acc_vouchers', function (Blueprint $t) {
            $t->string('sub_type', 24)->nullable()->after('type');              // journal: adjusting | closing | depreciation | payroll …
            $t->date('due_date')->nullable()->after('date');
            $t->boolean('is_advance')->default(false);                          // receipt/payment held as an advance, not against a bill
            $t->string('source_type', 24)->nullable();                          // system-generated: fiscal_year | asset | payroll | advance …
            $t->unsignedBigInteger('source_id')->nullable();
            $t->date('reverse_on')->nullable();                                 // adjusting entry that auto-reverses on this date
            $t->unsignedBigInteger('reverses_voucher_id')->nullable();
            $t->index(['company_id', 'source_type', 'source_id']);
        });

        Schema::create('acc_fiscal_years', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->index();
            $t->string('name', 30);
            $t->date('start_date');
            $t->date('end_date');
            $t->string('status', 8)->default('open');                           // open | closed
            $t->timestamp('closed_at')->nullable();
            $t->unsignedBigInteger('closed_by')->nullable();
            $t->unsignedBigInteger('closing_voucher_id')->nullable();
            $t->decimal('net_profit', 15, 2)->nullable();                       // result transferred to retained earnings
            $t->timestamps();
            $t->unique(['company_id', 'start_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acc_fiscal_years');
        Schema::table('acc_vouchers', function (Blueprint $t) {
            $t->dropIndex(['company_id', 'source_type', 'source_id']);
            $t->dropColumn(['sub_type', 'due_date', 'is_advance', 'source_type', 'source_id', 'reverse_on', 'reverses_voucher_id']);
        });
        Schema::table('acc_settings', fn (Blueprint $t) => $t->dropColumn(['chart_version', 'locked_until', 'petty_cash_limit', 'quotation_validity_days']));
        Schema::table('acc_ledgers', function (Blueprint $t) {
            $t->dropUnique(['company_id', 'code']);
            $t->dropColumn(['code', 'credit_limit', 'credit_days', 'credit_hold']);
        });
    }
};
