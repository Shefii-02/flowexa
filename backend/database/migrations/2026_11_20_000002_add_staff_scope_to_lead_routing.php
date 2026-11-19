<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who may receive a lead:  all | online (clocked in via HR attendance) | selected | selected_online.
 * On the company-wide rule (lead_assignment_rules) and on every category / campaign routing rule.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('lead_assignment_rules', function (Blueprint $table) {
            $table->string('staff_scope', 20)->default('all');   // existing behaviour: every eligible employee
            $table->json('staff_ids')->nullable();               // used by selected / selected_online
        });

        Schema::table('lead_routing_rules', function (Blueprint $table) {
            // existing rules were "these employees only" → selected
            $table->string('staff_scope', 20)->default('selected')->after('ends_at');
            $table->json('staff_ids')->nullable()->change();     // all / online rules have no list
        });
    }

    public function down(): void
    {
        Schema::table('lead_routing_rules', function (Blueprint $table) {
            $table->dropColumn('staff_scope');
        });
        Schema::table('lead_assignment_rules', function (Blueprint $table) {
            $table->dropColumn(['staff_scope', 'staff_ids']);
        });
    }
};
