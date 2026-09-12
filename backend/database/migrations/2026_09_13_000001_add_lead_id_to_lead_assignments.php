<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lead_assignments', function (Blueprint $table) {
            // Bridges the routing/notification engine (keyed by contact) back to the CRM Lead it
            // routed, so every accept/decline/timeout/transfer/AI-handoff step can be logged onto
            // that lead's own activity timeline and keep Lead.assigned_to in sync.
            $table->foreignId('lead_id')->nullable()->after('contact_id')->constrained('leads')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('lead_assignments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('lead_id');
        });
    }
};
