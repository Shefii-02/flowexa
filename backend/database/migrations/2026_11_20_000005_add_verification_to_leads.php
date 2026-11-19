<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Converted (enrolled) and closed (lost) leads get checked and approved by someone with the leads.verify permission. */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->timestamp('verified_at')->nullable()->after('lost_at');
            $table->unsignedBigInteger('verified_by')->nullable()->after('verified_at');
            $table->index(['company_id', 'stage', 'verified_at']);
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'stage', 'verified_at']);
            $table->dropColumn(['verified_at', 'verified_by']);
        });
    }
};
