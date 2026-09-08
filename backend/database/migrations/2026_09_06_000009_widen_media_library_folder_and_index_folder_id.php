<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `media_library.folder` shipped as a 4-value ENUM (images/videos/audio/
 * documents), so a file could never carry a custom folder's slug — every
 * upload into a custom folder was silently mislabelled "documents". Widen it to
 * a plain string; the real association is `folder_id` and this column is now
 * just a human-readable slug mirror.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement("ALTER TABLE media_library MODIFY folder VARCHAR(100) NOT NULL DEFAULT 'documents'");

        Schema::table('media_library', function (Blueprint $table) {
            $table->index('folder_id');
        });
    }

    public function down(): void
    {
        Schema::table('media_library', function (Blueprint $table) {
            $table->dropIndex(['folder_id']);
        });

        DB::statement("UPDATE media_library SET folder = 'documents' WHERE folder NOT IN ('images','videos','audio','documents')");
        DB::statement("ALTER TABLE media_library MODIFY folder ENUM('images','videos','audio','documents') NOT NULL DEFAULT 'images'");
    }
};
