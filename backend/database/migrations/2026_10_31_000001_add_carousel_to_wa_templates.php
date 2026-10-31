<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wa_templates', function (Blueprint $table) {
            // 'standard' (every existing template) or 'carousel' (2–10 swipeable cards).
            $table->string('template_type', 20)->default('standard')->after('category');
            // Every card of a carousel shares one media type: IMAGE or VIDEO.
            $table->string('carousel_media_format', 10)->nullable()->after('template_type');
            // [{uid, body, buttons:[…]}] — the text/structure of each card.
            $table->json('carousel_cards')->nullable()->after('buttons');
            // {uid: {handle, path, url}} — each card's sample media, kept apart from the cards so an
            // edit that rewrites the cards never loses an upload, and uploads can precede the save.
            $table->json('carousel_media')->nullable()->after('carousel_cards');
        });
    }

    public function down(): void
    {
        Schema::table('wa_templates', function (Blueprint $table) {
            $table->dropColumn(['template_type', 'carousel_media_format', 'carousel_cards', 'carousel_media']);
        });
    }
};
