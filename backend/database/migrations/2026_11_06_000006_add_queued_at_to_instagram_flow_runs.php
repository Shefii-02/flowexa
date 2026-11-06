<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // When a run first had to wait for DM budget — the FIFO key for the throttle queue.
        Schema::table('instagram_flow_runs', function (Blueprint $t) {
            $t->timestamp('queued_at')->nullable();
            $t->index(['instagram_account_id', 'wait_kind', 'queued_at'], 'ig_run_throttle_queue_idx');
        });
    }

    public function down(): void
    {
        Schema::table('instagram_flow_runs', function (Blueprint $t) {
            $t->dropIndex('ig_run_throttle_queue_idx');
            $t->dropColumn('queued_at');
        });
    }
};
