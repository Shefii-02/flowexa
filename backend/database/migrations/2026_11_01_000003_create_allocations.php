<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Settles part (or all) of an invoice/bill with a receipt, payment, credit/debit note, or an applied advance.
        Schema::create('acc_allocations', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->index();
            $t->unsignedBigInteger('payment_voucher_id')->index();   // the settling document
            $t->unsignedBigInteger('invoice_voucher_id')->index();   // the invoice / bill being settled
            $t->decimal('amount', 15, 2);
            $t->date('date');                                        // settlement date (drives point-in-time ageing)
            $t->unsignedBigInteger('journal_voucher_id')->nullable(); // set when an advance was applied (the adjusting journal)
            $t->timestamps();
            $t->index(['company_id', 'invoice_voucher_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acc_allocations');
    }
};
