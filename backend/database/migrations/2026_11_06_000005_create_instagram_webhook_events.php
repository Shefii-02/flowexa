<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Every webhook delivery we accept, for the Activity view / debugging / App Review evidence.
        Schema::create('instagram_webhook_events', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('instagram_account_id')->nullable()->index();
            $t->string('ig_user_id', 40)->nullable()->index();   // entry[].id
            $t->string('kind', 30);                              // comment | live_comment | message | postback | echo | story_reply | referral | other
            $t->string('field', 40)->nullable();                 // Meta field name (comments, messages, …)
            $t->string('status', 12)->default('queued');         // queued | processed | ignored | failed
            $t->string('note', 255)->nullable();                 // why ignored / error text
            $t->json('payload');
            $t->timestamp('processed_at')->nullable();
            $t->timestamps();

            $t->index(['company_id', 'created_at']);
        });
    }

    public function down(): void { Schema::dropIfExists('instagram_webhook_events'); }
};
