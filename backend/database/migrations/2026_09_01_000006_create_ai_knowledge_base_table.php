<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ai_knowledge_base', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('document_type', ['text', 'url', 'file'])->default('text');
            $table->longText('raw_content')->nullable();
            $table->string('file_path')->nullable();
            $table->string('source_url')->nullable();
            $table->enum('status', ['pending', 'processing', 'ready', 'failed'])->default('pending')->index();
            $table->integer('word_count')->default(0);
            $table->integer('chunk_count')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_knowledge_base');
    }
};
