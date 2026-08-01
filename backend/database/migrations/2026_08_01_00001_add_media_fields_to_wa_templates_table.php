<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{


    public function up(): void
    {
        Schema::table('wa_templates', function (Blueprint $table) {
            $table->string('footer_media_handle')->nullable()->after('footer');
            $table->string('footer_media_path')->nullable()->after('footer_media_handle');
            $table->string('footer_media_url')->nullable()->after('footer_media_path');
        });
    }

    public function down(): void
    {
        Schema::table('wa_templates', function (Blueprint $table) {
           $table->dropColumn(['footer_media_handle', 'footer_media_path', 'footer_media_url']);
        });
    }
};
