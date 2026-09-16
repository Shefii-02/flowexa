<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Self-service "I accidentally clocked out" flow: staff request the day be reopened
 *  (clock_out_at cleared so they can clock back in) instead of every mistaken clock-out
 *  needing a manager to go find and edit the raw attendance row. */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('hr_attendance', function (Blueprint $table) {
            $table->string('reopen_status', 20)->nullable()->after('overtime_status');
            $table->text('reopen_reason')->nullable()->after('reopen_status');
            $table->timestamp('reopen_requested_at')->nullable()->after('reopen_reason');
            $table->unsignedBigInteger('reopen_reviewed_by')->nullable()->after('reopen_requested_at');
            $table->timestamp('reopen_reviewed_at')->nullable()->after('reopen_reviewed_by');
        });
    }

    public function down(): void
    {
        Schema::table('hr_attendance', function (Blueprint $table) {
            $table->dropColumn([
                'reopen_status', 'reopen_reason', 'reopen_requested_at',
                'reopen_reviewed_by', 'reopen_reviewed_at',
            ]);
        });
    }
};
