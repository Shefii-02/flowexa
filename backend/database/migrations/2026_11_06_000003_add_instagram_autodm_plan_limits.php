<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $t) {
            $t->unsignedInteger('max_instagram_automations')->nullable();   // null = unlimited
            $t->unsignedInteger('max_instagram_dms_monthly')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('plans', fn (Blueprint $t) => $t->dropColumn(['max_instagram_automations', 'max_instagram_dms_monthly']));
    }
};
