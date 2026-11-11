<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Per-flow bot messages and timings (resolved check, no-response follow-up, session timeout). Null = defaults.
        Schema::table('flow_builders', fn (Blueprint $t) => $t->json('settings')->nullable());
    }

    public function down(): void
    {
        Schema::table('flow_builders', fn (Blueprint $t) => $t->dropColumn('settings'));
    }
};
