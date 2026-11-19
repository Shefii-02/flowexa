<?php

namespace App\Modules\Settings\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\SchedulerRun;
use App\Support\SchedulerMonitor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/** Superadmin → Cron Jobs: is the scheduler alive, what ran last, and the run history (with a clear option). */
class CronMonitorController extends Controller
{
    /** GET superadmin/cron */
    public function index(): JsonResponse
    {
        $beat = SchedulerMonitor::heartbeat();
        $tasks = SchedulerMonitor::tasks();
        $path = base_path();
        return response()->json([
            'scheduler' => $beat + [
                'cron_line' => "* * * * * cd {$path} && php artisan schedule:run >> /dev/null 2>&1",
                'tracked' => Schema::hasTable('scheduler_runs'),
                'server_time' => now()->toIso8601String(),
            ],
            'summary' => [
                'tasks' => count($tasks),
                'failing' => count(array_filter($tasks, fn ($t) => $t['status'] === 'failed')),
                'late' => count(array_filter($tasks, fn ($t) => $t['status'] === 'late')),
                'runs_24h' => array_sum(array_column($tasks, 'runs_24h')),
            ],
            'tasks' => $tasks,
        ]);
    }

    /** POST superadmin/cron/run  { task } — run one scheduled task right now (recorded in the history). */
    public function run(Request $request): JsonResponse
    {
        $d = $request->validate(['task' => ['required', 'string', 'max:190']]);
        $r = SchedulerMonitor::runNow($d['task']);
        abort_unless($r, 404, 'Unknown scheduled task.');
        $msg = $r['status'] === 'success' ? "Ran {$d['task']} in {$r['duration_ms']} ms." : "{$d['task']} failed: " . ($r['error'] ?? 'non-zero exit');
        return response()->json(['message' => $msg] + $r, $r['status'] === 'success' ? 200 : 500);
    }

    /** GET superadmin/cron/history?task=&status=&page= */
    public function history(Request $request): JsonResponse
    {
        if (!Schema::hasTable('scheduler_runs')) { return response()->json(['data' => [], 'total' => 0, 'last_page' => 1, 'current_page' => 1]); }
        $d = $request->validate(['task' => ['nullable', 'string', 'max:190'], 'status' => ['nullable', 'in:running,success,failed,skipped'], 'per_page' => ['nullable', 'integer', 'min:5', 'max:100']]);
        return response()->json(SchedulerRun::query()
            ->when($d['task'] ?? null, fn ($q, $t) => $q->where('task', $t))
            ->when($d['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('id')->paginate($d['per_page'] ?? 25));
    }

    /** DELETE superadmin/cron/history  (older_than_days optional — omitted clears everything) */
    public function clear(Request $request): JsonResponse
    {
        $d = $request->validate(['older_than_days' => ['nullable', 'integer', 'min:0', 'max:3650'], 'task' => ['nullable', 'string', 'max:190']]);
        if (!Schema::hasTable('scheduler_runs')) { return response()->json(['message' => 'Nothing to clear.', 'deleted' => 0]); }
        $n = SchedulerRun::query()
            ->when(isset($d['older_than_days']), fn ($q) => $q->where('started_at', '<', now()->subDays((int) $d['older_than_days'])))
            ->when($d['task'] ?? null, fn ($q, $t) => $q->where('task', $t))
            ->where('status', '!=', 'running')
            ->delete();
        return response()->json(['message' => "Cleared {$n} run(s) from the history.", 'deleted' => $n]);
    }
}
