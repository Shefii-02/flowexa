<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wa_templates', function (Blueprint $table) {
            // Meta CDN header URLs carry long signed query strings — 255 chars isn't enough.
            $table->text('header_sample_url')->nullable()->change();
            // Marketing message validity period (seconds). Null = Meta's default of 12 hours.
            $table->unsignedInteger('message_send_ttl_seconds')->nullable()->after('buttons');
        });
    }

    public function down(): void
    {
        Schema::table('wa_templates', function (Blueprint $table) {
            $table->dropColumn('message_send_ttl_seconds');
        });
    }
};
