<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('lead_activities', function (Blueprint $t) {
            // which way a call went: outgoing (we called), incoming (they called, answered), missed (they called, nobody answered)
            $t->string('direction', 10)->default('outgoing')->after('kind');
            $t->index(['company_id', 'direction', 'happened_at']);
        });
    }

    public function down(): void
    {
        Schema::table('lead_activities', function (Blueprint $t) {
            $t->dropIndex(['company_id', 'direction', 'happened_at']);
            $t->dropColumn('direction');
        });
    }
};
