<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_staff_profiles', function (Blueprint $table) {
            // Monthly performance/sales target for the staff member — used on the
            // Staff Setup screen and for target-vs-actual on incentives/payroll.
            $table->decimal('monthly_target', 12, 2)->nullable()->after('hourly_rate');
        });
    }

    public function down(): void
    {
        Schema::table('hr_staff_profiles', function (Blueprint $table) {
            $table->dropColumn('monthly_target');
        });
    }
};
