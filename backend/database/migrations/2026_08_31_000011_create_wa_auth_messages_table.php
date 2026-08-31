<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('wa_auth_messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name', 150);
            $table->enum('type', ['otp', 'welcome', 'password_reset', 'login_alert', 'custom']);
            $table->text('message_template');
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->index('company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_auth_messages');
    }
};
