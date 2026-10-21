<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('acc_entries', function (Blueprint $t) {
            // Date the bank cleared this entry (bank-statement date). NULL = not yet reconciled.
            $t->date('reconciled_on')->nullable()->after('narration');
            $t->index(['company_id', 'ledger_id', 'reconciled_on'], 'acc_entries_recon_idx');
        });
    }

    public function down(): void
    {
        Schema::table('acc_entries', function (Blueprint $t) {
            $t->dropIndex('acc_entries_recon_idx');
            $t->dropColumn('reconciled_on');
        });
    }
};
