<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        //

        Schema::table('survey_forms', function (Blueprint $table) {
            if (!Schema::hasColumn('survey_forms', 'flow_id')) {
                // Meta's Flow ID once this form has been registered + published as a
                // native WhatsApp Flow. Null = not published yet, falls back to the
                // sequential text-message survey instead.
                $table->string('flow_id')->nullable()->after('fields');
                // draft | published | deprecated — mirrors Meta's flow status
                $table->string('flow_status')->nullable()->after('flow_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('survey_forms', function (Blueprint $t) {
            $t->dropColumn(['flow_id', 'flow_status']);
        });
    }
};
