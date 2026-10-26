<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('widget_conversations', function (Blueprint $t) {
            // Company-wide "a staff member has seen everything up to here" — drives the Unread / Read tabs.
            $t->timestamp('staff_read_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('widget_conversations', fn (Blueprint $t) => $t->dropColumn('staff_read_at'));
    }
};
