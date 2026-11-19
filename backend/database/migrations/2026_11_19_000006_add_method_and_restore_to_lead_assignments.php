<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('lead_assignments', function (Blueprint $table) {
            // how the current owner was picked: algorithm | round_robin | manual | transfer | same_staff | restore
            $table->string('assignment_method', 30)->nullable()->after('assignment_type');
            // state captured when completing, so "Restore" can put it back
            $table->string('previous_status', 30)->nullable();
            $table->string('previous_lead_stage', 30)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('lead_assignments', function (Blueprint $table) {
            $table->dropColumn(['assignment_method', 'previous_status', 'previous_lead_stage']);
        });
    }
};
