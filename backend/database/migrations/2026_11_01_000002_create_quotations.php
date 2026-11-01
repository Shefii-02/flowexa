<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A quotation is a priced offer, not a posting: it never touches the books until it is converted to a sales invoice.
        Schema::create('acc_quotations', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->index();
            $t->string('number', 30);
            $t->date('date');
            $t->date('valid_until');
            $t->unsignedBigInteger('party_ledger_id');
            $t->string('place_of_supply', 2)->nullable();
            $t->boolean('is_interstate')->default(false);
            $t->string('status', 10)->default('draft');            // draft | sent | accepted | rejected | invoiced (expired is derived)
            $t->decimal('taxable', 15, 2)->default(0);
            $t->decimal('cgst', 15, 2)->default(0);
            $t->decimal('sgst', 15, 2)->default(0);
            $t->decimal('igst', 15, 2)->default(0);
            $t->decimal('total', 15, 2)->default(0);
            $t->string('subject', 200)->nullable();
            $t->text('notes')->nullable();
            $t->text('terms')->nullable();
            $t->unsignedBigInteger('converted_voucher_id')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'number']);
            $t->index(['company_id', 'status']);
        });

        Schema::create('acc_quotation_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('quotation_id')->index();
            $t->unsignedBigInteger('item_id')->nullable();
            $t->string('description');
            $t->string('hsn', 10)->nullable();
            $t->decimal('qty', 12, 3)->default(1);
            $t->decimal('rate', 15, 2)->default(0);
            $t->decimal('amount', 15, 2)->default(0);
            $t->decimal('gst_rate', 5, 2)->default(0);
            $t->decimal('cgst', 15, 2)->default(0);
            $t->decimal('sgst', 15, 2)->default(0);
            $t->decimal('igst', 15, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acc_quotation_items');
        Schema::dropIfExists('acc_quotations');
    }
};
