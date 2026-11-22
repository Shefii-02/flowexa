<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Per-staff mobile layout: {tabs:{todo:bool…}, profile_items:{…}, pages:[module ids]}. NULL = follow the company's layout. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->json('mobile_screens')->nullable()->after('mobile_access'));
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('mobile_screens'));
    }
};
