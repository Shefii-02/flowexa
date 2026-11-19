<?php

namespace App\Support;

use App\Models\SchedulerRun;
use Cron\CronExpression;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;

/**
 * Watches Laravel's scheduler: records every run (start, finish, failure) and a heartbeat each time the server's cron calls
 * `schedule:run`, so the superadmin can see whether cron is alive, what ran last and what failed.
 */
class SchedulerMonitor
{
    public const HEARTBEAT_KEY = 'scheduler:last_tick';
    /** schedule:run is expected every minute; more than this many seconds of silence = cron is not calling it. */
    public const STALE_AFTER = 150;

    /** @var array<string,int> task key => open scheduler_runs id (within this process) */
    private static array $open = [];

    public static function register(): void
    {
        Event::listen(CommandStarting::class, function (CommandStarting $e) {
            if (in_array($e->command, ['schedule:run', 'schedule:work'], true)) {
                try { Cache::forever(self::HEARTBEAT_KEY, now()->toIso8601String()); } catch (\Throwable) { /* never break the scheduler */ }
            }
        });
        Event::listen(ScheduledTaskStarting::class, fn ($e) => self::guard(fn () => self::started($e->task)));
        Event::listen(ScheduledTaskFinished::class, fn ($e) => self::guard(fn () => self::finished($e->task, (int) round($e->runtime * 1000), null)));
        Event::listen(ScheduledTaskFailed::class, fn ($e) => self::guard(fn () => self::finished($e->task, null, $e->exception)));
        Event::listen(ScheduledTaskSkipped::class, fn ($e) => self::guard(fn () => self::skipped($e->task)));
    }

    private static function guard(callable $fn): void
    {
        try { if (Schema::hasTable('scheduler_runs')) { $fn(); } } catch (\Throwable) { /* monitoring must never stop a job */ }
    }

    /** Human name of a scheduled task: its ->name() / description, or the artisan command ("leads:check-sla"). */
    public static function label(ScheduledEvent $e): string
    {
        $text = $e->description ?: (string) $e->command;
        $text = preg_replace('/^.*artisan[\'"]?\s+/', '', $text) ?: $text;
        return trim(str_replace(["'", '"'], '', $text)) ?: 'task';
    }

    private static function started(ScheduledEvent $e): void
    {
        $row = SchedulerRun::create(['task' => self::label($e), 'expression' => $e->expression, 'started_at' => now(), 'status' => 'running']);
        self::$open[spl_object_id($e)] = $row->id;
    }

    private static function finished(ScheduledEvent $e, ?int $ms, ?\Throwable $error): void
    {
        $id = self::$open[spl_object_id($e)] ?? null;
        $row = $id ? SchedulerRun::find($id) : SchedulerRun::create(['task' => self::label($e), 'expression' => $e->expression, 'started_at' => now()]);
        $failed = $error !== null || (is_int($e->exitCode) && $e->exitCode !== 0);
        $row?->update([
            'finished_at' => now(), 'duration_ms' => $ms ?? (int) round(abs(now()->diffInMilliseconds($row->started_at))),
            'status' => $failed ? 'failed' : 'success', 'exit_code' => is_int($e->exitCode) ? $e->exitCode : null,
            'error' => $error ? mb_substr($error::class . ': ' . $error->getMessage(), 0, 2000) : null,
        ]);
        unset(self::$open[spl_object_id($e)]);
    }

    private static function skipped(ScheduledEvent $e): void
    {
        SchedulerRun::create(['task' => self::label($e), 'expression' => $e->expression, 'started_at' => now(), 'finished_at' => now(), 'duration_ms' => 0, 'status' => 'skipped']);
    }

    /**
     * Superadmin "Run now": run one scheduled task immediately, recording it in the run history like a cron run.
     * @return array{status:string, duration_ms:int, output:string, error:?string}|null  null = no such task
     */
    public static function runNow(string $task): ?array
    {
        $events = app(Schedule::class)->events();
        if (!$events) {
            try { app(\Illuminate\Contracts\Console\Kernel::class)->bootstrap(); } catch (\Throwable) {}
            $events = app(Schedule::class)->events();
        }
        $event = collect($events)->first(fn ($e) => self::label($e) === $task);
        if (!$event) {
            return null;
        }

        @set_time_limit(300);
        $has = Schema::hasTable('scheduler_runs');
        $row = $has ? SchedulerRun::create(['task' => $task, 'expression' => $event->expression, 'started_at' => now(), 'status' => 'running']) : null;
        $start = microtime(true); $output = ''; $error = null; $code = 0;
        try {
            if ($event->command) {
                $code = \Illuminate\Support\Facades\Artisan::call($task);   // the label is the artisan command (+ arguments)
                $output = trim(\Illuminate\Support\Facades\Artisan::output());
            } else {
                $event->run(app());                                           // Schedule::call(...) closures
                $code = is_int($event->exitCode) ? $event->exitCode : 0;
            }
        } catch (\Throwable $e) {
            $error = mb_substr($e::class . ': ' . $e->getMessage(), 0, 2000); $code = 1;
        }
        $ms = (int) round((microtime(true) - $start) * 1000);
        $failed = $error !== null || $code !== 0;
        $row?->update(['finished_at' => now(), 'duration_ms' => $ms, 'status' => $failed ? 'failed' : 'success', 'exit_code' => $code, 'error' => $error]);

        return ['status' => $failed ? 'failed' : 'success', 'duration_ms' => $ms, 'output' => mb_substr($output, 0, 4000), 'error' => $error];
    }

    /** @return array{running:bool,last_tick:?string,seconds_ago:?int} */
    public static function heartbeat(): array
    {
        try { $iso = Cache::get(self::HEARTBEAT_KEY); } catch (\Throwable) { $iso = null; }
        $at = $iso ? \Illuminate\Support\Carbon::parse($iso) : null;
        $ago = $at ? (int) abs($at->diffInSeconds(now())) : null;
        return ['running' => $at !== null && $ago <= self::STALE_AFTER, 'last_tick' => $at?->toIso8601String(), 'seconds_ago' => $ago];
    }

    /** Every task in the schedule with its last run, next due time and health. */
    public static function tasks(): array
    {
        $has = Schema::hasTable('scheduler_runs');
        $last = $has ? SchedulerRun::query()->whereIn('id', SchedulerRun::selectRaw('MAX(id)')->groupBy('task'))->get()->keyBy('task') : collect();
        $lastOk = $has ? SchedulerRun::where('status', 'success')->selectRaw('task, MAX(started_at) as at')->groupBy('task')->pluck('at', 'task') : collect();
        $day = $has ? SchedulerRun::where('started_at', '>=', now()->subDay())->selectRaw("task, COUNT(*) as runs, SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) as failures")->groupBy('task')->get()->keyBy('task') : collect();
        $alive = self::heartbeat()['running'];

        $out = [];
        $events = app(Schedule::class)->events();
        if (!$events) {
            // In a web request the console routes (routes/console.php, where the schedule lives) are not loaded — load them.
            try { app(\Illuminate\Contracts\Console\Kernel::class)->bootstrap(); } catch (\Throwable) { /* fall through with what we have */ }
            $events = app(Schedule::class)->events();
        }
        foreach ($events as $e) {
            $name = self::label($e);
            $tz = $e->timezone ?: config('app.timezone');
            try {
                $cron = new CronExpression($e->expression);
                $next = $cron->getNextRunDate(now(), 0, false, $tz);
                $prev = $cron->getPreviousRunDate(now(), 0, false, $tz);
            } catch (\Throwable) { $next = $prev = null; }
            $run = $last->get($name);
            $status = 'waiting';
            if ($run) {
                $status = $run->status === 'failed' ? 'failed' : ($run->status === 'running' ? 'running' : 'ok');
            }
            // due at least 3 minutes ago, scheduler is alive, and nothing newer has run → it is late / missing
            if ($status !== 'failed' && $alive && $prev && \Illuminate\Support\Carbon::instance($prev)->lt(now()->subMinutes(3)) && (!$run || $run->started_at->lt($prev))) {
                $status = $run ? 'late' : 'waiting';
            }
            $out[] = [
                'task' => $name, 'expression' => $e->expression, 'next_due' => $next ? \Illuminate\Support\Carbon::instance($next)->toIso8601String() : null,
                'status' => $status,
                'last_run' => $run ? ['started_at' => $run->started_at?->toIso8601String(), 'status' => $run->status, 'duration_ms' => $run->duration_ms, 'error' => $run->error] : null,
                'last_success_at' => $lastOk->get($name) ? \Illuminate\Support\Carbon::parse($lastOk->get($name))->toIso8601String() : null,
                'runs_24h' => (int) ($day->get($name)->runs ?? 0), 'failures_24h' => (int) ($day->get($name)->failures ?? 0),
            ];
        }
        usort($out, fn ($a, $b) => strcmp($a['task'], $b['task']));
        return $out;
    }
}
