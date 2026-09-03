<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('wa_otp_services', function (Blueprint $table) {
            $table->string('delivery_channel', 20)->default('waha')->after('session_id');
            $table->unsignedBigInteger('wa_phone_number_id')->nullable()->after('delivery_channel');
        });
    }

    public function down(): void
    {
        Schema::table('wa_otp_services', function (Blueprint $table) {
            $table->dropColumn(['delivery_channel', 'wa_phone_number_id']);
        });
    }
};
