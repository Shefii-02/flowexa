<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Which billing periods a plan sells and the % off each one: {"3month":5,"6month":10,"yearly":20}. A missing key = not sold; monthly is always sold.
// NULL (every existing plan) = the old fixed offer: 3 months −5%, 6 months −10%, 12 months −20% (or the plan's own yearly price).
return new class extends Migration {
    public function up(): void
    {
        Schema::table('plans', fn (Blueprint $t) => $t->json('billing_options')->nullable());
    }

    public function down(): void
    {
        Schema::table('plans', fn (Blueprint $t) => $t->dropColumn('billing_options'));
    }
};
