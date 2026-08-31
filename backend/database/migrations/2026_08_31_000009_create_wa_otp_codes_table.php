<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('wa_otp_codes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('service_id');
            $table->string('phone', 30);
            $table->string('otp_code', 20);
            $table->string('reference_id', 100)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('domain', 255)->nullable();
            $table->enum('status', ['pending', 'verified', 'expired', 'failed'])->default('pending');
            $table->integer('attempts')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->foreign('service_id')->references('id')->on('wa_otp_services')->onDelete('cascade');
            $table->index(['company_id', 'phone', 'status']);
            $table->index(['otp_code', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_otp_codes');
    }
};
