<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meta_ad_sets', function (Blueprint $t) {
            $t->foreignId('audience_set_id')
                ->nullable()
                ->after('audience_template_id')
                ->constrained('meta_audience_sets')
                ->nullOnDelete();
        });

        Schema::table('meta_campaigns', function (Blueprint $t) {
            // The real Meta field is an ARRAY of categories (HOUSING / EMPLOYMENT / CREDIT /
            // ISSUES_ELECTIONS_POLITICS / ONLINE_GAMBLING_AND_GAMING). The legacy boolean
            // `special_ad_category` is kept for backward compatibility but no longer written.
            $t->json('special_ad_categories')->nullable()->after('special_ad_category');
        });
    }

    public function down(): void
    {
        Schema::table('meta_ad_sets', function (Blueprint $t) {
            $t->dropConstrainedForeignId('audience_set_id');
        });
        Schema::table('meta_campaigns', function (Blueprint $t) {
            $t->dropColumn('special_ad_categories');
        });
    }
};
