<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // a staff member's open-lead capacity no longer stops at 500 — and the column no longer stops at 65,535
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) { $t->unsignedInteger('max_leads')->default(50)->change(); });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) { $t->unsignedSmallInteger('max_leads')->default(50)->change(); });
    }
};
