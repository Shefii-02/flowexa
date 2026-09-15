<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_preferences', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            // CRM
            $t->boolean('new_lead_alert')->default(true);
            $t->boolean('ai_handoff_offers')->default(true);
            $t->boolean('sla_breach_alert')->default(true);
            $t->boolean('campaign_updates')->default(true);

            // HRM
            $t->boolean('leave_request_updates')->default(true);
            $t->boolean('payroll_released')->default(true);
            $t->boolean('attendance_reminders')->default(true);

            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
    }
};
