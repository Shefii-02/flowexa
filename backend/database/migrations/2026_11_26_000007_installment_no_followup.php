<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('lead_installments', function (Blueprint $t) {
            // true = no one is set to follow this installment up (not even the lead owner) — only HR can act on it.
            // false + assigned_to null = the lead's owner follows it up (the default).
            $t->boolean('no_followup')->default(false)->after('assigned_to');
        });
    }

    public function down(): void { Schema::table('lead_installments', fn (Blueprint $t) => $t->dropColumn('no_followup')); }
};
