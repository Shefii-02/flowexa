<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Basic HR — attendance (clock in/out), breaks, leave, and the per-staff
 * attendance profile. Punches come from the mobile app or web with a GPS
 * position; when the staff's attendance type is "gps" the position is checked
 * against the company office geofence.
 */
return new class extends Migration {
    public function up(): void
    {
        // ── Company HR configuration ─────────────────────────────────────────
        Schema::create('hr_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->unique();
            $table->decimal('office_lat', 10, 7)->nullable();
            $table->decimal('office_lng', 10, 7)->nullable();
            $table->unsignedInteger('geofence_radius_m')->default(150);
            $table->time('office_start')->default('09:00:00');
            $table->time('office_end')->default('18:00:00');
            $table->unsignedSmallInteger('early_window_minutes')->default(15);  // "success" window before office_start
            $table->unsignedSmallInteger('grace_minutes')->default(15);          // late-but-"warning" window after office_start
            $table->boolean('require_late_note')->default(true);
            $table->boolean('require_early_leave_note')->default(true);
            $table->boolean('overtime_needs_approval')->default(true);
            $table->decimal('overtime_multiplier', 4, 2)->default(1.50);
            $table->boolean('auto_availability')->default(true);   // attendance drives staff_availability
            $table->boolean('leave_auto_approve')->default(false);
            $table->string('timezone', 64)->default('Asia/Kolkata');
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
        });

        // ── Break types ─────────────────────────────────────────────────────
        Schema::create('hr_break_types', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name', 60);
            $table->unsignedSmallInteger('max_minutes')->nullable();
            $table->unsignedTinyInteger('daily_limit')->nullable();  // max sessions/day
            $table->boolean('is_paid')->default(true);
            $table->boolean('requires_gps')->default(true);
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->index(['company_id', 'is_active']);
        });

        // ── Per-staff attendance profile ────────────────────────────────────
        Schema::create('hr_staff_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('user_id')->unique();
            $table->string('attendance_type', 10)->default('manual');  // gps | manual
            $table->string('work_mode', 10)->default('wfo');           // wfo | wfh | hybrid
            $table->time('duty_start')->nullable();   // overrides hr_settings.office_start
            $table->time('duty_end')->nullable();
            $table->json('weekly_off')->nullable();   // [0..6] weekday numbers (0=Sunday)
            $table->decimal('monthly_salary', 12, 2)->nullable();
            $table->decimal('hourly_rate', 10, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        // ── Daily attendance ────────────────────────────────────────────────
        Schema::create('hr_attendance', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('user_id');
            $table->date('work_date');

            $table->timestamp('clock_in_at')->nullable();
            $table->timestamp('clock_out_at')->nullable();
            $table->decimal('clock_in_lat', 10, 7)->nullable();
            $table->decimal('clock_in_lng', 10, 7)->nullable();
            $table->decimal('clock_out_lat', 10, 7)->nullable();
            $table->decimal('clock_out_lng', 10, 7)->nullable();
            $table->unsignedInteger('clock_in_distance_m')->nullable();
            $table->unsignedInteger('clock_out_distance_m')->nullable();
            $table->boolean('clock_in_out_of_geofence')->default(false);

            $table->string('clock_in_status', 12)->nullable();  // info | primary | success | warning | danger
            $table->string('work_mode', 10)->nullable();        // captured at punch
            $table->string('source', 10)->default('web');       // mobile | web | admin

            $table->unsignedInteger('late_minutes')->default(0);
            $table->unsignedInteger('early_leave_minutes')->default(0);
            $table->unsignedInteger('overtime_minutes')->default(0);
            $table->unsignedInteger('break_minutes')->default(0);
            $table->unsignedInteger('worked_minutes')->default(0);

            $table->text('late_note')->nullable();
            $table->text('early_leave_note')->nullable();
            $table->text('overtime_note')->nullable();
            $table->string('overtime_status', 12)->default('none'); // none | pending | approved | rejected
            $table->unsignedBigInteger('overtime_approved_by')->nullable();
            $table->timestamp('overtime_reviewed_at')->nullable();

            $table->string('status', 12)->default('absent');  // present | absent | on_leave | half_day | weekly_off
            $table->string('note_color', 12)->nullable();      // danger | warning | success | info | primary
            $table->string('note_message', 255)->nullable();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['company_id', 'user_id', 'work_date']);
            $table->index(['company_id', 'work_date']);
        });

        // ── Break sessions ─────────────────────────────────────────────────
        Schema::create('hr_break_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('attendance_id');
            $table->unsignedBigInteger('break_type_id')->nullable();

            $table->timestamp('start_at');
            $table->timestamp('end_at')->nullable();
            $table->decimal('start_lat', 10, 7)->nullable();
            $table->decimal('start_lng', 10, 7)->nullable();
            $table->decimal('end_lat', 10, 7)->nullable();
            $table->decimal('end_lng', 10, 7)->nullable();
            $table->unsignedInteger('start_distance_m')->nullable();
            $table->unsignedInteger('end_distance_m')->nullable();

            $table->unsignedInteger('minutes')->nullable();
            $table->boolean('over_limit')->default(false);
            $table->unsignedInteger('over_by_minutes')->default(0);
            $table->string('start_status', 12)->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('attendance_id')->references('id')->on('hr_attendance')->cascadeOnDelete();
            $table->foreign('break_type_id')->references('id')->on('hr_break_types')->nullOnDelete();
            $table->index(['company_id', 'user_id']);
        });

        // ── Leave ──────────────────────────────────────────────────────────
        Schema::create('hr_leave_types', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name', 60);
            $table->boolean('is_paid')->default(true);
            $table->unsignedSmallInteger('max_days_per_year')->nullable();
            $table->boolean('requires_approval')->default(true);
            $table->string('color', 12)->default('info');
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->index(['company_id', 'is_active']);
        });

        Schema::create('hr_leave_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('leave_type_id');
            $table->date('start_date');
            $table->date('end_date');
            $table->decimal('days', 4, 1)->default(1);
            $table->boolean('half_day')->default(false);
            $table->text('reason')->nullable();
            $table->string('status', 12)->default('pending'); // pending | approved | rejected | cancelled
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('leave_type_id')->references('id')->on('hr_leave_types')->cascadeOnDelete();
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'user_id', 'start_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_leave_requests');
        Schema::dropIfExists('hr_leave_types');
        Schema::dropIfExists('hr_break_sessions');
        Schema::dropIfExists('hr_attendance');
        Schema::dropIfExists('hr_staff_profiles');
        Schema::dropIfExists('hr_break_types');
        Schema::dropIfExists('hr_settings');
    }
};
