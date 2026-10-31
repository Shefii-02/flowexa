<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_contacts', function (Blueprint $table) {
            // Straight from Meta's status webhook ("pricing" object): was this message billed, and at which rate.
            $table->boolean('billable')->nullable()->after('read_at');
            $table->string('pricing_category', 30)->nullable()->after('billable'); // marketing | utility | authentication | service | marketing_lite | referral_conversion
            $table->string('pricing_model', 20)->nullable()->after('pricing_category'); // PMP | CBP
            $table->index(['campaign_id', 'pricing_category']);
        });
    }

    public function down(): void
    {
        Schema::table('campaign_contacts', function (Blueprint $table) {
            $table->dropIndex(['campaign_id', 'pricing_category']);
            $table->dropColumn(['billable', 'pricing_category', 'pricing_model']);
        });
    }
};
