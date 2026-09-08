<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Attribute OTP codes and activity logs to the `wa_api_configs` row that
 * produced them, so the "Stats / usage" view on each config can aggregate
 * per-config counts. Nullable — legacy rows and requests that resolve no
 * named config keep `config_id` null.
 *
 * Also widens `wa_otp_logs.action` from its original 5-value ENUM to a plain
 * string: the Api Service already logs `utility` and `invoice_share` actions
 * that the ENUM rejected.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('wa_otp_codes', function (Blueprint $table) {
            $table->unsignedBigInteger('config_id')->nullable()->after('service_id');
            $table->foreign('config_id')->references('id')->on('wa_api_configs')->nullOnDelete();
            $table->index('config_id');
        });

        Schema::table('wa_otp_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('config_id')->nullable()->after('service_id');
            $table->foreign('config_id')->references('id')->on('wa_api_configs')->nullOnDelete();
            $table->index('config_id');
        });

        DB::statement("ALTER TABLE wa_otp_logs MODIFY action VARCHAR(30) NOT NULL");
    }

    public function down(): void
    {
        DB::statement(
            "ALTER TABLE wa_otp_logs MODIFY action ENUM('sent','verified','expired','failed','resend') NOT NULL"
        );

        Schema::table('wa_otp_codes', function (Blueprint $table) {
            $table->dropForeign(['config_id']);
            $table->dropColumn('config_id');
        });

        Schema::table('wa_otp_logs', function (Blueprint $table) {
            $table->dropForeign(['config_id']);
            $table->dropColumn('config_id');
        });
    }
};
