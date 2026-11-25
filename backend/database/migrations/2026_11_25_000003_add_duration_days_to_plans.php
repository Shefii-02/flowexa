<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // a custom-duration plan (e.g. a 7 or 14 day trial) can be counted in days instead of months
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $t) { $t->unsignedSmallInteger('duration_days')->nullable()->after('duration_months'); });
        Schema::table('company_plans', function (Blueprint $t) { $t->unsignedSmallInteger('duration_days')->nullable()->after('duration_months'); });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $t) { $t->dropColumn('duration_days'); });
        Schema::table('company_plans', function (Blueprint $t) { $t->dropColumn('duration_days'); });
    }
};
