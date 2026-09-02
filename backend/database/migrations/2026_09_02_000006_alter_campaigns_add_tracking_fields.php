<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            if (!Schema::hasColumn('campaigns', 'starts_at'))
                $table->timestamp('starts_at')->nullable()->after('scheduled_at');
            if (!Schema::hasColumn('campaigns', 'ends_at'))
                $table->timestamp('ends_at')->nullable()->after('starts_at');
            if (!Schema::hasColumn('campaigns', 'source_tracking_id'))
                $table->string('source_tracking_id', 100)->nullable()->unique()->after('ends_at');
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            foreach (['starts_at', 'ends_at', 'source_tracking_id'] as $col) {
                if (Schema::hasColumn('campaigns', $col)) $table->dropColumn($col);
            }
        });
    }
};
