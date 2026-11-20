<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Why a lead was closed (lost, or converted with a note). Cleared when the lead is reopened; the full history stays in the lead's timeline. */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->text('close_reason')->nullable()->after('lost_at');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('close_reason');
        });
    }
};
