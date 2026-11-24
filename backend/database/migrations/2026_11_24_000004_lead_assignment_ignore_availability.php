<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** "All staff" and "Selected staff (any time)" hand a lead out without asking whether the person is online — the notification job needs to know. */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('lead_assignments', function (Blueprint $t) {
            $t->boolean('ignore_availability')->default(false)->after('candidate_staff_ids');
        });
    }

    public function down(): void
    {
        Schema::table('lead_assignments', fn (Blueprint $t) => $t->dropColumn('ignore_availability'));
    }
};
