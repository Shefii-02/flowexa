<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        //

        Schema::table('companies', function (Blueprint $table) {
            $table->string('meta_app_id', 255)->nullable()->after('wa_business_id');
            $table->string('wa_profile_id', 255)->nullable()->after('meta_app_id');
            $table->string('wa_webhook_token', 255)->nullable()->after('wa_profile_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
        Schema::dropIfExists('flow_builders');

        Schema::table('companies', function (Blueprint $t) {
            $t->dropColumn('meta_app_id');
            $t->dropColumn('wa_profile_id');
            $t->dropColumn('wa_webhook_token');
        });
    }
};
