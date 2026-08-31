<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('media_library', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->enum('folder', ['images', 'videos', 'audio', 'documents'])->default('images');
            $table->string('filename', 255);
            $table->string('original_name', 255);
            $table->string('display_name', 255)->nullable();
            $table->string('url', 1000);
            $table->string('disk', 30)->default('local');
            $table->string('path', 500);
            $table->unsignedBigInteger('size')->default(0);
            $table->string('mime_type', 150)->nullable();
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->foreign('uploaded_by')->references('id')->on('users')->onDelete('set null');
            $table->index(['company_id', 'folder']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_library');
    }
};
