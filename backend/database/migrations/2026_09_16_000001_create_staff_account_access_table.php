<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Opt-in restriction of a staff member to specific connected accounts —
 * WA Chat sessions, WA Cloud numbers, Instagram accounts, Meta Ads accounts.
 * A user with zero rows for a given account_type is unrestricted for that type
 * (see User::allowedAccountIds()) — restriction only starts once an admin has
 * explicitly granted at least one row, so existing staff keep today's behaviour
 * (see everything) until an admin deliberately scopes them down.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_account_access', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('account_type', 30); // wa_session | phone_number | instagram_account | meta_ads_account — matches Lead.origin_type
            $table->unsignedBigInteger('account_id');
            $table->timestamp('created_at')->nullable();

            $table->unique(['user_id', 'account_type', 'account_id'], 'staff_account_access_unique');
            $table->index(['company_id', 'account_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_account_access');
    }
};
