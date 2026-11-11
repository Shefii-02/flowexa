<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Long flow descriptions (generated/imported flows) overflowed varchar(255).
        Schema::table('flow_builders', fn (Blueprint $t) => $t->text('description')->nullable()->change());
    }

    public function down(): void
    {
        Schema::table('flow_builders', fn (Blueprint $t) => $t->string('description', 255)->nullable()->change());
    }
};
