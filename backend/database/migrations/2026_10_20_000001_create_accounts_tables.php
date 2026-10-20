<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Per-company accounting + GST profile.
        Schema::create('acc_settings', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->unique();
            $t->string('legal_name')->nullable();
            $t->string('gstin', 15)->nullable();
            $t->string('pan', 10)->nullable();
            $t->string('state_code', 2)->nullable();   // GST state code, e.g. 32 = Kerala
            $t->text('address')->nullable();
            $t->string('invoice_prefix', 10)->default('INV');
            $t->unsignedTinyInteger('fy_start_month')->default(4); // April
            $t->boolean('gst_registered')->default(true);
            $t->timestamps();
        });

        // Chart of accounts (Tally "ledgers"). Parties are ledgers too.
        Schema::create('acc_ledgers', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->index();
            $t->string('name');
            // asset | liability | income | expense | equity
            $t->string('nature', 12);
            // cash | bank | debtor | creditor | sales | purchase | direct_income | indirect_income | direct_expense
            // | indirect_expense | gst_output | gst_input | capital | fixed_asset | loan | other
            $t->string('group', 24)->default('other');
            $t->decimal('opening_balance', 15, 2)->default(0);
            $t->string('opening_type', 2)->default('dr'); // dr | cr
            $t->string('gstin', 15)->nullable();
            $t->string('state_code', 2)->nullable();
            $t->string('phone', 20)->nullable();
            $t->string('email')->nullable();
            $t->text('address')->nullable();
            $t->string('pan', 10)->nullable();
            $t->string('system_key', 24)->nullable(); // marks seeded ledgers the engine posts to
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['company_id', 'name']);
            $t->index(['company_id', 'group']);
        });

        Schema::create('acc_vouchers', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->index();
            // sales | purchase | receipt | payment | expense | income | journal | contra
            $t->string('type', 12);
            $t->string('number', 30);
            $t->date('date');
            $t->unsignedBigInteger('party_ledger_id')->nullable();
            $t->unsignedBigInteger('ledger_id')->nullable();      // expense / income ledger
            $t->unsignedBigInteger('account_ledger_id')->nullable(); // cash / bank / paid-from
            $t->string('place_of_supply', 2)->nullable();
            $t->boolean('is_interstate')->default(false);
            $t->decimal('taxable', 15, 2)->default(0);
            $t->decimal('cgst', 15, 2)->default(0);
            $t->decimal('sgst', 15, 2)->default(0);
            $t->decimal('igst', 15, 2)->default(0);
            $t->decimal('total', 15, 2)->default(0);
            $t->string('reference_no', 60)->nullable(); // supplier bill no / cheque / UTR
            $t->text('narration')->nullable();
            $t->string('attachment')->nullable();
            $t->boolean('is_cancelled')->default(false);
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'type', 'number']);
            $t->index(['company_id', 'date']);
        });

        Schema::create('acc_voucher_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('voucher_id')->index();
            $t->string('description');
            $t->string('hsn', 10)->nullable();
            $t->decimal('qty', 12, 3)->default(1);
            $t->decimal('rate', 15, 2)->default(0);
            $t->decimal('amount', 15, 2)->default(0);   // taxable value
            $t->decimal('gst_rate', 5, 2)->default(0);
            $t->decimal('cgst', 15, 2)->default(0);
            $t->decimal('sgst', 15, 2)->default(0);
            $t->decimal('igst', 15, 2)->default(0);
        });

        // Double-entry lines: every voucher posts rows whose debits equal credits.
        Schema::create('acc_entries', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->index();
            $t->unsignedBigInteger('voucher_id')->index();
            $t->unsignedBigInteger('ledger_id')->index();
            $t->date('date');
            $t->decimal('debit', 15, 2)->default(0);
            $t->decimal('credit', 15, 2)->default(0);
            $t->string('narration')->nullable();
            $t->index(['company_id', 'ledger_id', 'date']);
        });
    }

    public function down(): void
    {
        foreach (['acc_entries', 'acc_voucher_items', 'acc_vouchers', 'acc_ledgers', 'acc_settings'] as $tbl) {
            Schema::dropIfExists($tbl);
        }
    }
};
