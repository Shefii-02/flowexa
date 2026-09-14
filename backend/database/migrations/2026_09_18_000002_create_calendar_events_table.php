<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Appointments / followups a company books against a contact or lead. This is the source of
 * truth on our side regardless of whether Google is connected — booking works standalone
 * (google_event_id stays null) and, when the company's Google account is connected with the
 * Calendar scope, CalendarService best-effort mirrors it into their real Google Calendar too
 * (so it shows up in the staff member's own calendar app, with reminders, on their phone).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('crm_task_id')->nullable()->constrained('crm_tasks')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('title');
            $table->text('description')->nullable();
            $table->string('location')->nullable();
            $table->string('meet_link')->nullable();

            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('timezone')->default('Asia/Kolkata');

            // 'appointment' (customer-facing booking), 'followup' (staff follow-up on a lead),
            // 'callback' (from HumanHandoffService's outside-hours offer), 'meeting' (internal).
            $table->string('type')->default('appointment');
            $table->string('status')->default('scheduled'); // scheduled | completed | cancelled | no_show

            // Best-effort mirror into the company's connected Google Calendar — null if never
            // synced (no Google connection, or the sync attempt failed; source of truth stays here).
            $table->string('google_event_id')->nullable();
            $table->text('google_sync_error')->nullable();

            $table->timestamp('reminder_sent_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'starts_at']);
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_events');
    }
};
