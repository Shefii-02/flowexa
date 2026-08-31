<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('wa_chat_templates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name', 200);
            $table->string('category', 100)->nullable();
            $table->string('language', 10)->default('en');
            $table->enum('header_type', ['none', 'text', 'image', 'video', 'document'])->default('none');
            $table->text('header_content')->nullable();
            $table->text('body');
            $table->string('footer', 255)->nullable();
            $table->json('buttons')->nullable();
            $table->enum('status', ['draft', 'active', 'archived'])->default('draft');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_chat_templates');
    }
};
