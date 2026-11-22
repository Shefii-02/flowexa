<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Per-staff WEB menu: {sections:[allowed section ids]}. NULL = follow the company layout. Managed separately from users.mobile_screens (the app). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->json('web_screens')->nullable()->after('mobile_screens'));
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('web_screens'));
    }
};
