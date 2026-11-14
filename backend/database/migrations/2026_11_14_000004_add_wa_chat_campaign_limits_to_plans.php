<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // WA Chat campaigns (open-wa sender) get their own caps, separate from WA Cloud campaigns. NULL = unlimited.
        Schema::table('plans', function (Blueprint $t) {
            $t->unsignedInteger('max_chat_campaigns')->nullable()->after('max_campaigns');
            $t->unsignedInteger('max_chat_campaigns_per_month')->nullable()->after('max_chat_campaigns');
            $t->unsignedInteger('max_chat_campaign_recipients')->nullable()->after('max_chat_campaigns_per_month');
        });
    }

    public function down(): void
    {
        Schema::table('plans', fn (Blueprint $t) => $t->dropColumn(['max_chat_campaigns', 'max_chat_campaigns_per_month', 'max_chat_campaign_recipients']));
    }
};
