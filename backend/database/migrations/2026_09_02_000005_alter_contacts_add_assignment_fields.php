<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            if (!Schema::hasColumn('contacts', 'current_assignment_id'))
                $table->unsignedBigInteger('current_assignment_id')->nullable()->after('crm_id');
            if (!Schema::hasColumn('contacts', 'total_leads_count'))
                $table->integer('total_leads_count')->default(0)->after('current_assignment_id');
            if (!Schema::hasColumn('contacts', 'first_lead_at'))
                $table->timestamp('first_lead_at')->nullable()->after('total_leads_count');
            if (!Schema::hasColumn('contacts', 'last_lead_at'))
                $table->timestamp('last_lead_at')->nullable()->after('first_lead_at');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            foreach (['current_assignment_id', 'total_leads_count', 'first_lead_at', 'last_lead_at'] as $col) {
                if (Schema::hasColumn('contacts', $col)) $table->dropColumn($col);
            }
        });
    }
};
