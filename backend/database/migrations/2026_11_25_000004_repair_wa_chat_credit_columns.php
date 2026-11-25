<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // 2026_11_21_000001_create_wa_chat_billing is recorded as run on the live DB, but plans.wa_chat_credits_per_message is missing there (saving a plan fails).
    // Adds only what is absent, so it is safe wherever the original did apply.
    public function up(): void
    {
        if (!Schema::hasColumn('plans', 'wa_chat_credits_per_message')) {
            Schema::table('plans', fn (Blueprint $t) => $t->unsignedTinyInteger('wa_chat_credits_per_message')->nullable());
        }
        if (!Schema::hasColumn('companies', 'wa_chat_billing')) {
            Schema::table('companies', fn (Blueprint $t) => $t->string('wa_chat_billing', 12)->default('included'));
        }
        if (!Schema::hasColumn('companies', 'wa_chat_credits_per_message')) {
            Schema::table('companies', fn (Blueprint $t) => $t->unsignedTinyInteger('wa_chat_credits_per_message')->nullable());
        }
    }

    public function down(): void {}
};
