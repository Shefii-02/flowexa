<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('wa_export_jobs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->enum('export_type', ['chat_list', 'group_list', 'group_participants', 'message_history']);
            $table->string('session_id', 100);
            $table->json('filters')->nullable();
            $table->enum('status', ['pending', 'processing', 'done', 'failed'])->default('pending');
            $table->string('file_url', 500)->nullable();
            $table->integer('row_count')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
            $table->index('company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_export_jobs');
    }
};
