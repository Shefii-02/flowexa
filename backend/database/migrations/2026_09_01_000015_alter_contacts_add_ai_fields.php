<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            if (!Schema::hasColumn('contacts', 'lead_score'))
                $table->integer('lead_score')->default(0)->after('crm_id');
            if (!Schema::hasColumn('contacts', 'lead_score_updated_at'))
                $table->timestamp('lead_score_updated_at')->nullable()->after('lead_score');
            if (!Schema::hasColumn('contacts', 'lead_stage'))
                $table->string('lead_stage', 50)->nullable()->after('lead_score_updated_at');
            if (!Schema::hasColumn('contacts', 'last_sentiment'))
                $table->string('last_sentiment', 20)->nullable()->after('lead_stage');
            if (!Schema::hasColumn('contacts', 'detected_intent'))
                $table->string('detected_intent', 50)->nullable()->after('last_sentiment');
            if (!Schema::hasColumn('contacts', 'buying_signals_count'))
                $table->integer('buying_signals_count')->default(0)->after('detected_intent');
            if (!Schema::hasColumn('contacts', 'objections_count'))
                $table->integer('objections_count')->default(0)->after('buying_signals_count');
            if (!Schema::hasColumn('contacts', 'meta_ai_profile'))
                $table->json('meta_ai_profile')->nullable()->after('objections_count');
            if (!Schema::hasColumn('contacts', 'conversation_summary'))
                $table->text('conversation_summary')->nullable()->after('meta_ai_profile');
            if (!Schema::hasColumn('contacts', 'summary_updated_at'))
                $table->timestamp('summary_updated_at')->nullable()->after('conversation_summary');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $cols = ['lead_score', 'lead_score_updated_at', 'lead_stage', 'last_sentiment',
                     'detected_intent', 'buying_signals_count', 'objections_count',
                     'meta_ai_profile', 'conversation_summary', 'summary_updated_at'];
            foreach ($cols as $col) {
                if (Schema::hasColumn('contacts', $col)) $table->dropColumn($col);
            }
        });
    }
};
