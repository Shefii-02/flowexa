<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The e-invoice schema needs a 6-digit pincode and a location (city) for seller and buyer.
        Schema::table('acc_settings', function (Blueprint $t) {
            $t->string('pincode', 6)->nullable()->after('address');
            $t->string('city', 100)->nullable()->after('pincode');
        });
        Schema::table('acc_ledgers', function (Blueprint $t) {
            $t->string('pincode', 6)->nullable()->after('address');
            $t->string('city', 100)->nullable()->after('pincode');
        });
    }

    public function down(): void
    {
        Schema::table('acc_settings', fn (Blueprint $t) => $t->dropColumn(['pincode', 'city']));
        Schema::table('acc_ledgers', fn (Blueprint $t) => $t->dropColumn(['pincode', 'city']));
    }
};
