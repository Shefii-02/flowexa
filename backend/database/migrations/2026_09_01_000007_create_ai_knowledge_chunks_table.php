<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ai_knowledge_chunks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('knowledge_base_id')->index();
            $table->unsignedBigInteger('company_id')->index();
            $table->text('content');
            $table->integer('chunk_index')->default(0);
            $table->json('tfidf_vector')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('knowledge_base_id')->references('id')->on('ai_knowledge_base')->onDelete('cascade');
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_knowledge_chunks');
    }
};
