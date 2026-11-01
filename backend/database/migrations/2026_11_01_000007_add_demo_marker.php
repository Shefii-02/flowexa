<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Remembers exactly which rows the demo seeder created so they can be removed again without touching real data.
        Schema::table('acc_settings', function (Blueprint $t) {
            $t->timestamp('demo_seeded_at')->nullable();
            $t->text('demo_marker')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('acc_settings', fn (Blueprint $t) => $t->dropColumn(['demo_seeded_at', 'demo_marker']));
    }
};
