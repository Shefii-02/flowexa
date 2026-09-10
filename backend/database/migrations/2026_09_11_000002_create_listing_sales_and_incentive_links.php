<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Per-item incentive override — beats the category rule when set.
        Schema::table('listings', function (Blueprint $table) {
            $table->decimal('incentive_percentage', 5, 2)->nullable()->after('price_unit');
        });

        // Let a lead carry the item + amount it converted on, so reaching
        // "enrolled" can auto-record a sale.
        Schema::table('leads', function (Blueprint $table) {
            $table->foreignId('listing_id')->nullable()->after('campaign_id')->constrained('listings')->nullOnDelete();
            $table->decimal('sale_value', 12, 2)->nullable()->after('listing_id');
        });

        Schema::create('listing_sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('listing_id')->nullable()->constrained('listings')->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            // The staff member credited for the sale — one per sale.
            $table->foreignId('staff_id')->constrained('users')->cascadeOnDelete();
            $table->string('item_label', 200)->nullable();   // snapshot of the item name
            $table->decimal('amount', 12, 2)->default(0);
            $table->string('currency', 8)->default('INR');
            $table->date('sold_at');
            // The incentive row this sale generated (if any).
            $table->foreignId('incentive_id')->nullable()->constrained('hr_incentives')->nullOnDelete();
            $table->string('note', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'staff_id', 'sold_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_sales');
        Schema::table('leads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('listing_id');
            $table->dropColumn('sale_value');
        });
        Schema::table('listings', function (Blueprint $table) {
            $table->dropColumn('incentive_percentage');
        });
    }
};
