<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // "Resend failed" is a one-time action: a row that has been resent once is marked here and
        // never offered for resending again.
        foreach (['openwa_message_logs', 'campaign_contacts'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->timestamp('resent_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['openwa_message_logs', 'campaign_contacts'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn('resent_at');
            });
        }
    }
};
