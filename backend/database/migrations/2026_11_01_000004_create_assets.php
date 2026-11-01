<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acc_assets', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->index();
            $t->string('tag', 30)->nullable();                         // asset tag / serial
            $t->string('name');
            $t->string('category', 12);                                // property | equipment | vehicle | intangible
            $t->date('purchase_date');
            $t->decimal('cost', 15, 2);
            $t->decimal('salvage_value', 15, 2)->default(0);
            $t->unsignedSmallInteger('useful_life_months')->default(60);
            $t->string('method', 4)->default('sl');                    // sl = straight line, wdv = written-down value
            $t->decimal('wdv_rate', 5, 2)->nullable();                 // % per year, for wdv
            $t->decimal('opening_accumulated', 15, 2)->default(0);     // depreciation already charged before it was entered here
            $t->decimal('accumulated', 15, 2)->default(0);             // opening_accumulated + every posted run
            $t->date('opening_to')->nullable();                        // existing asset: month-end it was already depreciated up to
            $t->date('last_depreciated_to')->nullable();
            $t->string('status', 10)->default('active');               // active | disposed
            $t->date('disposed_on')->nullable();
            $t->decimal('disposal_proceeds', 15, 2)->nullable();
            $t->decimal('disposal_gain_loss', 15, 2)->nullable();
            $t->unsignedBigInteger('acquisition_voucher_id')->nullable();
            $t->unsignedBigInteger('disposal_voucher_id')->nullable();
            $t->string('location', 120)->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
        });

        Schema::create('acc_asset_depreciations', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->index();
            $t->unsignedBigInteger('asset_id')->index();
            $t->date('period_end');
            $t->decimal('amount', 15, 2);
            $t->unsignedBigInteger('voucher_id')->nullable();
            $t->timestamps();
            $t->unique(['asset_id', 'period_end']);                    // one charge per asset per month — a re-run can never double-post
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acc_asset_depreciations');
        Schema::dropIfExists('acc_assets');
    }
};
