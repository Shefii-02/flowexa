<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            // Mirrors enrolled_at: stamped the moment a lead's stage becomes "lost", so
            // COALESCE(enrolled_at, lost_at) gives a single "closed date" for both outcomes.
            if (!Schema::hasColumn('leads', 'lost_at')) {
                $table->timestamp('lost_at')->nullable()->after('enrolled_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            if (Schema::hasColumn('leads', 'lost_at')) {
                $table->dropColumn('lost_at');
            }
        });
    }
};
