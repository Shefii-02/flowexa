<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('lead_assignment_notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('assignment_id');
            $table->unsignedBigInteger('staff_id');
            $table->enum('notification_type', ['new_lead','ai_offer','reminder','transfer'])->default('new_lead');
            $table->enum('channel', ['push','web','both'])->default('both');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->enum('response', ['accepted','declined','no_response'])->nullable();
            $table->integer('response_delay_seconds')->nullable();
            $table->timestamps();

            $table->index(['assignment_id', 'staff_id']);
            $table->index(['staff_id', 'sent_at']);

            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('assignment_id')->references('id')->on('lead_assignments')->cascadeOnDelete();
            $table->foreign('staff_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_assignment_notifications');
    }
};
