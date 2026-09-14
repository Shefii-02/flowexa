<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audience sets so far only had first-class support for "interests" and "behaviors" —
 * Meta's detailed targeting actually spans several more flexible_spec categories
 * (life events, family status, relationship status, education, income bracket, industry,
 * job title, generation), plus the newer Advantage+ detailed-targeting expansion controls
 * (targeting_relaxation_types, targeting_optimization) that let Meta broaden delivery
 * beyond the exact detailed targeting specified. `demographics` holds the former as a
 * flat, category-keyed JSON object (same {id,name} pair shape already used for interests/
 * behaviors) so MetaAdsService::compileTargetingSpec() can fold it straight into the same
 * flexible_spec group Meta expects.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meta_audience_sets', function (Blueprint $table) {
            $table->json('demographics')->nullable()->after('behaviors');
            $table->json('targeting_relaxation_types')->nullable()->after('exclusions');
            $table->string('targeting_optimization')->nullable()->after('targeting_relaxation_types');
        });
    }

    public function down(): void
    {
        Schema::table('meta_audience_sets', function (Blueprint $table) {
            $table->dropColumn(['demographics', 'targeting_relaxation_types', 'targeting_optimization']);
        });
    }
};
