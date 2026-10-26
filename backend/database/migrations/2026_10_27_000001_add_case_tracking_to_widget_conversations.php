<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('widget_conversations', function (Blueprint $t) {
            // open (bot chatting) | closed (solved) | pending (not solved) | unread (waiting for staff) | attended (staff took it)
            $t->string('case_status', 16)->default('open')->after('status')->index();
            $t->unsignedBigInteger('attended_by')->nullable()->after('case_status');      // staff who took it — kept after the chat ends
            $t->timestamp('attended_at')->nullable()->after('attended_by');
            $t->timestamp('closed_at')->nullable()->after('attended_at');
            $t->unsignedBigInteger('duplicate_of_id')->nullable()->after('closed_at');    // same visitor re-raised an open issue

            // Dissatisfaction flag for admin study: not_solved | no_staff | repeated | staff_flag
            $t->string('flag_reason', 24)->nullable()->after('duplicate_of_id');
            $t->timestamp('flagged_at')->nullable()->after('flag_reason');
            $t->timestamp('reviewed_at')->nullable()->after('flagged_at');
            $t->unsignedBigInteger('reviewed_by')->nullable()->after('reviewed_at');
            $t->text('review_note')->nullable()->after('reviewed_by');
            $t->index(['company_id', 'flagged_at']);
        });
    }

    public function down(): void
    {
        Schema::table('widget_conversations', function (Blueprint $t) {
            $t->dropIndex(['company_id', 'flagged_at']);
            $t->dropColumn(['case_status', 'attended_by', 'attended_at', 'closed_at', 'duplicate_of_id',
                'flag_reason', 'flagged_at', 'reviewed_at', 'reviewed_by', 'review_note']);
        });
    }
};
