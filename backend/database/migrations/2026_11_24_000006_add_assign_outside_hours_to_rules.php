<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** "Also assign outside working hours": off = a lead that arrives out of hours waits for the AI agent / next working day (today's behaviour). */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('lead_assignment_rules', function (Blueprint $t) {
            $t->boolean('assign_outside_hours')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('lead_assignment_rules', fn (Blueprint $t) => $t->dropColumn('assign_outside_hours'));
    }
};
