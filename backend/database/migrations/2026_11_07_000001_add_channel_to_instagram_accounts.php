<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One account model, two channels: 'instagram' (an IG professional account) and 'facebook' (a Page used for
        // Messenger; ig_user_id then holds the Page id). Flows, runs, queue, dedupe and monitoring are shared.
        Schema::table('instagram_accounts', function (Blueprint $t) {
            $t->string('channel', 12)->default('instagram')->after('id');
            $t->index(['company_id', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::table('instagram_accounts', function (Blueprint $t) {
            $t->dropIndex(['company_id', 'channel']);
            $t->dropColumn('channel');
        });
    }
};
