<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WA Chat (open-wa) messages are paid from the company's wallet, one charge per message, taken when WhatsApp confirms delivery.
 *   companies.wa_chat_billing              wallet = each delivered message costs credits | included = not charged (set by the superadmin per company).
 *                                          Companies that exist when this runs get 'included'; new ones get 'wallet'.
 *   companies.wa_chat_credits_per_message  credits taken per delivered message (the wallet counts 1 credit = 1 message)
 *   wa_chat_charges                        one row per charged message — the unique key is what makes a repeated delivery webhook harmless
 * WA Cloud is separate: it already follows companies.wa_config (own_account = Meta bills the company, the wallet is not touched).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $t) {
            // Existing companies are added as 'included' so deploying this charges nobody by surprise; the superadmin switches a company to 'wallet' when it should start paying.
            $t->string('wa_chat_billing', 12)->default('included')->after('wa_config');
            $t->unsignedTinyInteger('wa_chat_credits_per_message')->default(1)->after('wa_chat_billing');
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
            $t->string('source', 12)->default('chat');            // chat | campaign | api | test — what sent it, when that is known
            $t->unsignedSmallInteger('credits')->default(1);
            $t->string('status', 10)->default('charged');         // charged | unbilled (delivered while the wallet was empty)
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
    }
};
