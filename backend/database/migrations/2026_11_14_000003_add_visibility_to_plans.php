<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $t) {
            // false = a private plan: not on the public pricing page, sign-up or a company's upgrade list; staff can still assign it.
            $t->boolean('is_public')->default(true)->after('is_active');
            // set = archived: kept for the companies still on it and for reports, but offered to nobody (admin side only).
            $t->timestamp('archived_at')->nullable()->after('is_public');
        });
    }

    public function down(): void
    {
        Schema::table('plans', fn (Blueprint $t) => $t->dropColumn(['is_public', 'archived_at']));
    }
};
