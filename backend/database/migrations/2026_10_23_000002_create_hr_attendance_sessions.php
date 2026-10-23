<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Multiple clock-in/clock-out sessions per day (split shifts, accidental clock-outs).
 *  `hr_attendance` stays the one-row-per-day summary; each punch pair is a session row. */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('hr_attendance_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('attendance_id');

            $table->timestamp('clock_in_at');
            $table->timestamp('clock_out_at')->nullable();
            $table->decimal('clock_in_lat', 10, 7)->nullable();
            $table->decimal('clock_in_lng', 10, 7)->nullable();
            $table->decimal('clock_out_lat', 10, 7)->nullable();
            $table->decimal('clock_out_lng', 10, 7)->nullable();
            $table->unsignedInteger('clock_in_distance_m')->nullable();
            $table->unsignedInteger('clock_out_distance_m')->nullable();
            $table->string('clock_in_photo_url')->nullable();
            $table->string('clock_out_photo_url')->nullable();
            $table->text('clock_in_radius_reason')->nullable();
            $table->text('clock_out_radius_reason')->nullable();
            $table->text('clock_in_note')->nullable();
            $table->text('clock_out_note')->nullable();
            $table->string('source', 10)->default('web');
            $table->timestamps();

            $table->foreign('attendance_id')->references('id')->on('hr_attendance')->cascadeOnDelete();
            $table->index(['attendance_id', 'clock_in_at']);
            $table->index(['company_id', 'user_id']);
        });

        // Every existing day with a clock-in becomes a single session.
        DB::table('hr_attendance')->whereNotNull('clock_in_at')->orderBy('id')->each(function ($a) {
            DB::table('hr_attendance_sessions')->insert([
                'company_id' => $a->company_id, 'user_id' => $a->user_id, 'attendance_id' => $a->id,
                'clock_in_at' => $a->clock_in_at, 'clock_out_at' => $a->clock_out_at,
                'clock_in_lat' => $a->clock_in_lat, 'clock_in_lng' => $a->clock_in_lng,
                'clock_out_lat' => $a->clock_out_lat, 'clock_out_lng' => $a->clock_out_lng,
                'clock_in_distance_m' => $a->clock_in_distance_m, 'clock_out_distance_m' => $a->clock_out_distance_m,
                'clock_in_photo_url' => $a->clock_in_photo_url ?? null, 'clock_out_photo_url' => $a->clock_out_photo_url ?? null,
                'clock_in_radius_reason' => $a->clock_in_radius_reason ?? null, 'clock_out_radius_reason' => $a->clock_out_radius_reason ?? null,
                'clock_in_note' => $a->late_note, 'clock_out_note' => $a->early_leave_note ?? $a->overtime_note,
                'source' => $a->source ?? 'web',
                'created_at' => $a->created_at, 'updated_at' => $a->updated_at,
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_attendance_sessions');
    }
};
