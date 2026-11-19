<?php

namespace Tests\Feature;

use App\Models\SchedulerRun;
use App\Modules\Settings\Http\Controllers\CronMonitorController;
use App\Support\SchedulerMonitor;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

class SchedulerMonitorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('scheduler_runs', function (Blueprint $t) {
            $t->id(); $t->string('task', 190); $t->string('expression', 60)->nullable(); $t->timestamp('started_at'); $t->timestamp('finished_at')->nullable();
            $t->unsignedInteger('duration_ms')->nullable(); $t->string('status', 12)->default('running'); $t->smallInteger('exit_code')->nullable(); $t->text('error')->nullable();
        });
        Cache::forget(SchedulerMonitor::HEARTBEAT_KEY);
    }

    private function task(string $name)
    {
        return app(Schedule::class)->call(fn () => true)->name($name)->everyMinute();
    }

    public function test_a_successful_run_is_recorded_with_start_finish_and_duration(): void
    {
        $t = $this->task('unit:ok');
        event(new ScheduledTaskStarting($t));
        event(new ScheduledTaskFinished($t, 0.25));
        $r = SchedulerRun::where('task', 'unit:ok')->first();
        $this->assertSame('success', $r->status);
        $this->assertSame(250, (int) $r->duration_ms);
        $this->assertNotNull($r->finished_at);
    }

    public function test_a_failed_run_keeps_the_error(): void
    {
        $t = $this->task('unit:bad');
        event(new ScheduledTaskStarting($t));
        event(new ScheduledTaskFailed($t, new \RuntimeException('boom')));
        $r = SchedulerRun::where('task', 'unit:bad')->first();
        $this->assertSame('failed', $r->status);
        $this->assertStringContainsString('boom', $r->error);
    }

    public function test_heartbeat_follows_schedule_run_and_goes_stale(): void
    {
        $this->assertFalse(SchedulerMonitor::heartbeat()['running']);                   // cron never called it
        event(new CommandStarting('schedule:run', new ArrayInput([]), new NullOutput()));
        $this->assertTrue(SchedulerMonitor::heartbeat()['running']);
        Cache::forever(SchedulerMonitor::HEARTBEAT_KEY, now()->subMinutes(10)->toIso8601String());
        $this->assertFalse(SchedulerMonitor::heartbeat()['running']);                   // cron stopped 10 minutes ago
        event(new CommandStarting('migrate', new ArrayInput([]), new NullOutput()));     // other commands do not count
        $this->assertFalse(SchedulerMonitor::heartbeat()['running']);
    }

    public function test_tasks_list_shows_last_run_and_failures(): void
    {
        $t = $this->task('unit:list');
        event(new ScheduledTaskStarting($t)); event(new ScheduledTaskFailed($t, new \RuntimeException('x')));
        $row = collect(SchedulerMonitor::tasks())->firstWhere('task', 'unit:list');
        $this->assertSame('failed', $row['status']);
        $this->assertSame(1, $row['failures_24h']);
        $this->assertSame('failed', $row['last_run']['status']);
        $this->assertNotNull($row['next_due']);
    }

    public function test_history_can_be_filtered_and_cleared(): void
    {
        SchedulerRun::create(['task' => 'a', 'started_at' => now()->subDays(40), 'status' => 'success']);
        SchedulerRun::create(['task' => 'a', 'started_at' => now(), 'status' => 'failed']);
        SchedulerRun::create(['task' => 'b', 'started_at' => now(), 'status' => 'success']);
        $ctl = new CronMonitorController();

        $this->assertSame(1, $ctl->history(Request::create('/', 'GET', ['status' => 'failed']))->getData()->total);

        $this->assertSame(1, $ctl->clear(Request::create('/', 'DELETE', ['older_than_days' => 30]))->getData()->deleted);   // only the 40-day-old one
        $this->assertSame(2, SchedulerRun::count());
        $this->assertSame(1, $ctl->clear(Request::create('/', 'DELETE', ['task' => 'a']))->getData()->deleted);
        $this->assertSame(1, $ctl->clear(Request::create('/', 'DELETE'))->getData()->deleted);                              // everything
        $this->assertSame(0, SchedulerRun::count());
    }

    public function test_clearing_never_deletes_a_run_that_is_still_in_progress(): void
    {
        SchedulerRun::create(['task' => 'long', 'started_at' => now(), 'status' => 'running']);
        (new CronMonitorController())->clear(Request::create('/', 'DELETE'));
        $this->assertSame(1, SchedulerRun::count());
    }
}
