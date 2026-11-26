<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 2026_11_25_000004 re-created companies.wa_chat_billing on the live DB with the default 'included', so every company there was silently
// "not charged". New companies must default to 'wallet' (the app's intended default). Existing rows are NOT touched — a superadmin picks
// "Wallet" per company (or run: UPDATE companies SET wa_chat_billing = 'wallet').
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasColumn('companies', 'wa_chat_billing') && DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE companies MODIFY wa_chat_billing VARCHAR(12) NOT NULL DEFAULT 'wallet'");
        }
    }

    public function down(): void {}
};
