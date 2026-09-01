<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('wa_chat_templates', function (Blueprint $table) {
            $table->json('media_blocks')->nullable()->after('buttons');
        });
    }

    public function down(): void
    {
        Schema::table('wa_chat_templates', function (Blueprint $table) {
            $table->dropColumn('media_blocks');
        });
    }
};
