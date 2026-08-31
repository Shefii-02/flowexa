<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('wa_otp_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('service_id');
            $table->string('phone', 30);
            $table->enum('action', ['sent', 'verified', 'expired', 'failed', 'resend']);
            $table->string('ip_address', 45)->nullable();
            $table->string('domain', 255)->nullable();
            $table->integer('response_ms')->default(0);
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->foreign('service_id')->references('id')->on('wa_otp_services')->onDelete('cascade');
            $table->index(['company_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_otp_logs');
    }
};
