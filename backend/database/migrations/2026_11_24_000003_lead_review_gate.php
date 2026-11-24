<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Review before it becomes a lead. Per source: off (create the lead at once, as today) | smart (check the conversation —
 * a real enquiry becomes a lead, anything else waits) | all (every new contact waits for a team head). What waits sits in
 * held_leads with the topic the conversation was judged to be, and the message it was judged on.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('lead_source_settings', function (Blueprint $t) {
            $t->string('review_mode', 10)->default('off')->after('is_enabled');
        });
        Schema::table('held_leads', function (Blueprint $t) {
            $t->string('topic', 30)->nullable()->after('held_reason');            // lead_enquiry | technical | billing | complaint | existing_customer | other
            $t->decimal('topic_confidence', 3, 2)->nullable()->after('topic');
            $t->string('sample_text', 400)->nullable()->after('topic_confidence');  // the customer's latest message, for the reviewer
        });
    }

    public function down(): void
    {
        Schema::table('held_leads', fn (Blueprint $t) => $t->dropColumn(['topic', 'topic_confidence', 'sample_text']));
        Schema::table('lead_source_settings', fn (Blueprint $t) => $t->dropColumn('review_mode'));
    }
};
