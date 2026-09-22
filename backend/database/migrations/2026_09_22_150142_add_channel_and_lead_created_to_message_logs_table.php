<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('message_logs', function (Blueprint $table) {
            // Which webhook this message came in on — WA Chat (open-wa) and WA Cloud (Meta) are
            // separate integrations with separate webhook handlers; existing rows predate this
            // column and are all WA Cloud (WA Chat never wrote to this table until now), so
            // backfill accordingly rather than leaving them ambiguous.
            $table->string('channel', 20)->nullable()->after('direction');

            // Whether this inbound message resulted in a NEW lead (set by the lead-attribution
            // service at the moment of logging — not computed later, since a lead created minutes
            // afterward by a different message would otherwise be misattributed here).
            $table->boolean('lead_created')->default(false)->after('status');

            $table->index(['company_id', 'channel', 'created_at']);
        });

        DB::table('message_logs')->whereNull('channel')->update(['channel' => 'wa_cloud']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('message_logs', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'channel', 'created_at']);
            $table->dropColumn(['channel', 'lead_created']);
        });
    }
};
