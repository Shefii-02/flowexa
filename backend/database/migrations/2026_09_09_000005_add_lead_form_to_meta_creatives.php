<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meta_ad_creatives', function (Blueprint $t) {
            // Set on a lead-ad creative: the Instant Form the CTA opens.
            $t->foreignId('lead_form_id')
                ->nullable()
                ->after('video_thumbnail_url')
                ->constrained('meta_lead_forms')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('meta_ad_creatives', function (Blueprint $t) {
            $t->dropConstrainedForeignId('lead_form_id');
        });
    }
};
