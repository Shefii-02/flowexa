<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('lead_assignment_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->unique();
            $table->boolean('auto_assign_enabled')->default(true);
            // Algorithm weights (should sum to 100)
            $table->integer('weight_availability')->default(30);
            $table->integer('weight_max_leads')->default(25);
            $table->integer('weight_performance')->default(25);
            $table->integer('weight_workload')->default(20);
            // SLA
            $table->integer('sla_minutes')->default(30);
            $table->integer('ai_takeover_after_minutes')->default(30);
            // Notification (Uber-style)
            $table->enum('notification_mode', ['auto','uber','hybrid'])->default('hybrid');
            $table->integer('notification_gap_seconds')->default(30);
            $table->integer('notification_timeout_seconds')->default(60);
            $table->integer('max_notification_rounds')->default(3);
            // Duplicate lead
            $table->integer('duplicate_window_days')->default(90);
            $table->enum('duplicate_action', ['assign_same_staff','create_new','merge','notify_admin'])->default('assign_same_staff');
            // Working hours
            $table->time('working_hours_start')->default('09:00:00');
            $table->time('working_hours_end')->default('18:00:00');
            $table->json('working_days')->nullable();
            $table->string('timezone', 50)->default('Asia/Kolkata');
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_assignment_rules');
    }
};
