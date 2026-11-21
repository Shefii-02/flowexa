<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WA Chat (open-wa) CAMPAIGN, API/OTP and TEST messages are paid from the company's wallet, one charge per message, taken when WhatsApp confirms delivery. Normal chat messages are free.
 *   companies.wa_chat_billing              wallet = each delivered message costs credits | included = not charged (set by the superadmin per company).
 *                                          Companies that exist when this runs get 'included'; new ones get 'wallet'.
 *   plans.wa_chat_credits_per_message      credits taken per delivered message, set per PLAN (blank = 1, 0 = free)
 *   companies.wa_chat_credits_per_message  optional per-company override of the plan's rate (null = follow the plan)
 *   wa_chat_charges                        one row per billable message, created when it is sent and settled when it is delivered — the unique key is what makes a repeated delivery webhook harmless
 * WA Cloud is separate: it already follows companies.wa_config (own_account = Meta bills the company, the wallet is not touched).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $t) {
            // Existing companies are added as 'included' so deploying this charges nobody by surprise; the superadmin switches a company to 'wallet' when it should start paying.
            $t->string('wa_chat_billing', 12)->default('included')->after('wa_config');
            $t->unsignedTinyInteger('wa_chat_credits_per_message')->nullable()->after('wa_chat_billing');   // null = follow the plan
        });
        Schema::table('plans', function (Blueprint $t) {
            $t->unsignedTinyInteger('wa_chat_credits_per_message')->nullable();   // credits per delivered campaign / API message; blank = 1, 0 = free
        });
        // …and companies created from now on start on the wallet.
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE companies ALTER COLUMN wa_chat_billing SET DEFAULT 'wallet'");
        }

        Schema::create('wa_chat_charges', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('session_name', 100)->nullable();
            $t->string('wa_message_id', 191);
            $t->string('phone', 40)->nullable();
            $t->string('source', 12)->default('campaign');        // campaign | api | test — only these are billed; normal chat messages are never registered
            $t->unsignedSmallInteger('credits')->default(1);
            $t->string('status', 10)->default('pending');         // pending (sent, awaiting delivery) | charging | charged | unbilled (delivered while the wallet was empty)
            $t->unsignedBigInteger('wallet_transaction_id')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'wa_message_id']);
            $t->index(['company_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_chat_charges');
        Schema::table('companies', fn (Blueprint $t) => $t->dropColumn(['wa_chat_billing', 'wa_chat_credits_per_message']));
        Schema::table('plans', fn (Blueprint $t) => $t->dropColumn('wa_chat_credits_per_message'));
    }
};
