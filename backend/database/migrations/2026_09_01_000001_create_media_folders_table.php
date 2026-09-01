<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('media_folders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name', 100);
            $table->string('slug', 100);
            // null = accessible by all roles; JSON array of role names = restricted
            $table->json('permissions')->nullable();
            // system = auto-created (images/videos/audio/documents), never deleted via API
            $table->boolean('is_system')->default(false);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
            $table->unique(['company_id', 'slug']);
            $table->index('company_id');
        });

        Schema::table('media_library', function (Blueprint $table) {
            $table->unsignedBigInteger('folder_id')->nullable()->after('folder');
            $table->foreign('folder_id')->references('id')->on('media_folders')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('media_library', function (Blueprint $table) {
            $table->dropForeign(['folder_id']);
            $table->dropColumn('folder_id');
        });
        Schema::dropIfExists('media_folders');
    }
};
