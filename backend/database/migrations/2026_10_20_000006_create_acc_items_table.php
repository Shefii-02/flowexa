<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acc_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->index();
            $t->string('name');
            $t->string('sku', 40)->nullable();
            $t->string('hsn', 10)->nullable();
            $t->string('unit', 10)->default('NOS');
            $t->decimal('gst_rate', 5, 2)->default(18);
            $t->decimal('sale_rate', 15, 2)->default(0);
            $t->decimal('purchase_rate', 15, 2)->default(0);
            $t->boolean('track_stock')->default(true);      // false = a service
            $t->decimal('opening_qty', 15, 3)->default(0);
            $t->decimal('opening_value', 15, 2)->default(0); // total cost of the opening quantity
            $t->decimal('reorder_level', 15, 3)->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['company_id', 'name']);
        });

        Schema::table('acc_voucher_items', function (Blueprint $t) {
            $t->unsignedBigInteger('item_id')->nullable()->after('voucher_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('acc_voucher_items', function (Blueprint $t) { $t->dropIndex(['item_id']); $t->dropColumn('item_id'); });
        Schema::dropIfExists('acc_items');
    }
};
