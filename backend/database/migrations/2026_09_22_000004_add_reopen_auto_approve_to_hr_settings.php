<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Whether a staff member's "I accidentally clocked out" reopen request needs a manager's
 *  sign-off (the default) or is granted automatically the moment it's submitted. */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('hr_settings', function (Blueprint $table) {
            $table->boolean('reopen_auto_approve')->default(false)->after('leave_auto_approve');
        });
    }

    public function down(): void
    {
        Schema::table('hr_settings', function (Blueprint $table) {
            $table->dropColumn('reopen_auto_approve');
        });
    }
};
