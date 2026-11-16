<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Optional "only offer this lead to these staff" list (WA Cloud reply routing → sales-team handover). NULL = any eligible staff. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lead_assignments', function (Blueprint $t) {
            $t->json('candidate_staff_ids')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('lead_assignments', function (Blueprint $t) {
            $t->dropColumn('candidate_staff_ids');
        });
    }
};
