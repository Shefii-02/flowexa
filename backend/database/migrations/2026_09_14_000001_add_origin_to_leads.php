<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            // "Lead Source" (the existing `source` column) is the channel — website, whatsapp_cloud,
            // wa_chat, instagram, meta_ads, manual, etc. "Lead Origin" (these three columns) is WHICH
            // specific number/session/account/campaign within that channel — a company can run several
            // WhatsApp Cloud numbers, WA Chat sessions, Instagram accounts and ad campaigns at once, and
            // this answers "which one actually brought this lead in". origin_id is intentionally
            // unconstrained (no FK) since it points at a different table depending on origin_type, and
            // origin_label is a denormalized snapshot so the lead still reads sensibly even if the
            // number/account it names is later renamed or removed.
            $table->string('origin_type', 30)->nullable()->after('source');
            $table->unsignedBigInteger('origin_id')->nullable()->after('origin_type');
            $table->string('origin_label', 150)->nullable()->after('origin_id');

            $table->index(['company_id', 'origin_type', 'origin_id'], 'leads_origin_idx');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex('leads_origin_idx');
            $table->dropColumn(['origin_type', 'origin_id', 'origin_label']);
        });
    }
};
