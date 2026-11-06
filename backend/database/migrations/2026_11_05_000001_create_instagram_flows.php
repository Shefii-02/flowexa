<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Node-based automation graphs (React Flow JSON: nodes + edges).
        Schema::create('instagram_flows', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('instagram_account_id')->constrained('instagram_accounts')->cascadeOnDelete();
            $t->string('name', 150);
            $t->string('trigger_type', 20)->default('comment');   // comment | dm  (denormalised from the trigger node)
            $t->json('nodes');
            $t->json('edges');
            $t->boolean('is_active')->default(false);
            $t->unsignedSmallInteger('priority')->default(0);
            $t->unsignedInteger('triggered_count')->default(0);
            $t->timestamp('last_triggered_at')->nullable();
            $t->timestamps();
            $t->softDeletes();

            $t->index(['instagram_account_id', 'is_active', 'trigger_type'], 'ig_flow_acct_active_trigger_idx');
        });

        // One execution of a flow for one person. `wait_token` bumps every time the run parks, so a
        // re-delivered button tap / stale timer can't advance a run that has already moved on.
        Schema::create('instagram_flow_runs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('instagram_account_id')->constrained('instagram_accounts')->cascadeOnDelete();
            $t->foreignId('instagram_flow_id')->constrained('instagram_flows')->cascadeOnDelete();
            $t->foreignId('instagram_conversation_id')->nullable()->constrained('instagram_conversations')->nullOnDelete();
            $t->string('igsid', 40);
            $t->string('comment_id', 80)->nullable();
            $t->string('status', 10)->default('running');         // running | waiting | done | failed
            $t->string('current_node', 40)->nullable();
            $t->string('wait_kind', 10)->nullable();              // click | reply | delay | throttle
            $t->unsignedInteger('wait_token')->default(0);
            $t->timestamp('wait_expires_at')->nullable();
            $t->json('context')->nullable();
            $t->text('error')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamps();

            $t->index(['instagram_account_id', 'igsid', 'status'], 'ig_run_acct_igsid_status_idx');
            $t->index(['instagram_flow_id', 'igsid'], 'ig_run_flow_igsid_idx');
        });

        Schema::table('instagram_conversations', function (Blueprint $t) {
            $t->json('tags')->nullable();
            $t->boolean('follows_business')->nullable();          // last result of the follower check
            $t->timestamp('follow_checked_at')->nullable();
            $t->boolean('needs_agent')->default(false);           // a flow handed this thread to a human
        });
    }

    public function down(): void
    {
        Schema::table('instagram_conversations', function (Blueprint $t) {
            $t->dropColumn(['tags', 'follows_business', 'follow_checked_at', 'needs_agent']);
        });
        Schema::dropIfExists('instagram_flow_runs');
        Schema::dropIfExists('instagram_flows');
    }
};
