<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// One row per run of a scheduled task (cron job) — feeds Superadmin → Cron Jobs (last run, history, failures).
return new class extends Migration {
    public function up(): void
    {
        Schema::create('scheduler_runs', function (Blueprint $t) {
            $t->id();
            $t->string('task', 190);
            $t->string('expression', 60)->nullable();
            $t->timestamp('started_at');
            $t->timestamp('finished_at')->nullable();
            $t->unsignedInteger('duration_ms')->nullable();
            $t->string('status', 12)->default('running');      // running | success | failed | skipped
            $t->smallInteger('exit_code')->nullable();
            $t->text('error')->nullable();
            $t->index(['task', 'started_at']);
            $t->index('started_at');
        });
    }

    public function down(): void { Schema::dropIfExists('scheduler_runs'); }
};
