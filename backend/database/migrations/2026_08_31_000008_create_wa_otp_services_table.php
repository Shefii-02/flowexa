<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('wa_otp_services', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->unique();
            $table->string('api_token', 255)->unique();
            $table->timestamp('api_token_created_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('allowed_domains')->nullable();
            $table->json('allowed_packages')->nullable();
            $table->integer('otp_expiry_minutes')->default(10);
            $table->integer('otp_length')->default(6);
            $table->text('otp_message_template')->comment('Your OTP is {{otp}}. Valid for {{expiry}} minutes. Do not share.')->nullable();
            $table->string('session_id', 100)->nullable();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_otp_services');
    }
};
