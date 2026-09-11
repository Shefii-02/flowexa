<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            // WhatsApp-Web (open-wa) session cap — parallel to max_phone_numbers (cloud).
            $table->unsignedSmallInteger('max_wa_sessions')->nullable()->after('max_phone_numbers');
            // Warn the company this many days before the plan / trial expires.
            $table->unsignedSmallInteger('alert_before_days')->default(7)->after('duration_months');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['max_wa_sessions', 'alert_before_days']);
        });
    }
};
