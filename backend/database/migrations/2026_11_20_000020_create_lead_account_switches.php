<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lead capture on/off per ACCOUNT, not just per source: one WA Chat session, one WhatsApp Cloud number, one Instagram account, one website
 * widget or one Meta ad account can be switched off while the rest of the source keeps creating leads. What a switched-off account
 * receives goes to Held leads, exactly like a switched-off source.
 *   lead_account_switches.origin_type/origin_id use the same values as leads.origin_type/origin_id:
 *   wa_session | phone_number | instagram_account | widget | meta_ads_account.   No row = on.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('lead_account_switches', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('origin_type', 30);
            $t->unsignedBigInteger('origin_id');
            $t->boolean('is_enabled')->default(true);
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'origin_type', 'origin_id']);
        });

        Schema::table('held_leads', function (Blueprint $t) {
            $t->string('held_reason', 12)->default('source_off')->after('status');   // source_off | account_off
            $t->string('account_type', 30)->nullable()->after('origin_label');       // the account it came through (differs from origin for Meta Ads: origin = campaign)
            $t->unsignedBigInteger('account_id')->nullable()->after('account_type');
            $t->index(['company_id', 'status', 'account_type', 'account_id'], 'held_leads_account_idx');
        });
    }

    public function down(): void
    {
        Schema::table('held_leads', function (Blueprint $t) {
            $t->dropIndex('held_leads_account_idx');
            $t->dropColumn(['held_reason', 'account_type', 'account_id']);
        });
        Schema::dropIfExists('lead_account_switches');
    }
};
