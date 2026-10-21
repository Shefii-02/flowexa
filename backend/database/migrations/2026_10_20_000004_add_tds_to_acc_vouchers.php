<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('acc_vouchers', function (Blueprint $t) {
            $t->string('tds_section', 10)->nullable()->after('total');   // e.g. 194C, 194J
            $t->decimal('tds_rate', 5, 2)->default(0)->after('tds_section');
            $t->decimal('tds_amount', 15, 2)->default(0)->after('tds_rate'); // deducted from what we owe the party
        });
    }

    public function down(): void
    {
        Schema::table('acc_vouchers', fn (Blueprint $t) => $t->dropColumn(['tds_section', 'tds_rate', 'tds_amount']));
    }
};
