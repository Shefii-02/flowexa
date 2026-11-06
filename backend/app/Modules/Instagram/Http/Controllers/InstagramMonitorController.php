<?php

namespace App\Modules\Instagram\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\{InstagramAccount, InstagramFlowRun};
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\{Cache, DB, RateLimiter};

/** Live DM-budget / queue monitor plus delivery analytics for the AutoDM dashboard. */
class InstagramMonitorController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $cid   = auth()->user()->company_id;
        $days  = ['7d' => 7, '30d' => 30, '90d' => 90][$request->query('range', '7d')] ?? 7;
        $since = now()->subDays($days)->startOfDay();
        $allowed = auth()->user()->allowedAccountIds('instagram_account');
        $accounts = InstagramAccount::where('company_id', $cid)->where('channel', $request->query('channel', 'instagram'))->when($allowed !== null, fn ($q) => $q->whereIn('id', $allowed))->get();
        $ids = $accounts->pluck('id');

        // ── per-account budget + queue ──
        $monitor = $accounts->map(function (InstagramAccount $a) {
            $cap = $a->channel === 'facebook' ? (int) config('services.messenger.dm_hourly_cap', 1000) : (int) config('services.instagram.dm_hourly_cap', 190);
            $key  = "ig-dm:{$a->id}";
            $used = RateLimiter::attempts($key);
            $queue = InstagramFlowRun::where('instagram_account_id', $a->id)->where('status', 'waiting')->where('wait_kind', 'throttle')
                ->orderBy('queued_at')->orderBy('id');
            $next = (clone $queue)->with('conversation:id,participant_username')->limit(20)->get()->values()->map(function ($r, $i) {
                $text = $r->comment_id ? DB::table('instagram_comment_events')->where('ig_comment_id', $r->comment_id)->value('comment_text') : ($r->ctx('trigger_text'));
                return [
                    'position' => $i + 1, 'run_id' => $r->id, 'username' => $r->conversation?->participant_username ?? $r->ctx('username'),
                    'text' => mb_substr((string) $text, 0, 100), 'queued_at' => $r->queued_at, 'kind' => $r->comment_id ? 'comment' : 'dm',
                    'expires_at' => $r->comment_id ? $r->created_at?->copy()->addDays(7) : null,
                ];
            });
            return [
                'account_id' => $a->id, 'username' => $a->username, 'status' => $a->status ?? 'active',
                'hourly_cap' => $cap, 'used' => min($used, $cap), 'remaining' => max(0, $cap - $used),
                'resets_in_seconds' => $used > 0 ? RateLimiter::availableIn($key) : 0,
                'queued' => (clone $queue)->count(), 'oldest_queued_at' => (clone $queue)->value('queued_at'),
                'next_up' => $next,
            ];
        })->values();

        // ── plan monthly allowance ──
        $plan = auth()->user()->company?->plan;
        $monthCap = $plan?->max_instagram_dms_monthly;
        $monthly = ['cap' => $monthCap, 'used' => (int) Cache::get("ig-dm-month:{$cid}:" . now()->format('Ym'), 0)];

        // ── DMs per hour, last 24h (bucketed in PHP: portable across MySQL/SQLite) ──
        $sent = DB::table('instagram_messages as m')->join('instagram_conversations as c', 'c.id', '=', 'm.instagram_conversation_id')
            ->whereIn('c.instagram_account_id', $ids)->where('m.direction', 'out')->where('m.source', 'flow')
            ->where('m.created_at', '>=', now()->subHours(24))->pluck('m.created_at');
        $buckets = [];
        for ($i = 23; $i >= 0; $i--) $buckets[now()->subHours($i)->format('Y-m-d H:00')] = 0;
        foreach ($sent as $t) { $k = \Carbon\Carbon::parse($t)->format('Y-m-d H:00'); if (isset($buckets[$k])) $buckets[$k]++; }

        // ── run outcomes + failure reasons + funnel ──
        $runs = DB::table('instagram_flow_runs')->where('company_id', $cid)->whereIn('instagram_account_id', $ids)->where('created_at', '>=', $since);
        $byStatus = (clone $runs)->selectRaw('status, COUNT(*) n')->groupBy('status')->pluck('n', 'status');
        $reasons = (clone $runs)->where('status', 'failed')->whereNotNull('error')->selectRaw('error, COUNT(*) n')->groupBy('error')->orderByDesc('n')->limit(6)->get();
        $clicked = (clone $runs)->where('context->link_clicked', true)->count();
        $followed = (clone $runs)->where('context->gained_follower', true)->count();
        $leads = DB::table('instagram_conversations')->whereIn('instagram_account_id', $ids)->whereNotNull('lead_created_at')->where('lead_created_at', '>=', $since)->count();
        $total = (int) $byStatus->sum();

        // ── webhook health ──
        $events = DB::table('instagram_webhook_events')->where('company_id', $cid)->where('created_at', '>=', $since)->selectRaw('status, COUNT(*) n')->groupBy('status')->pluck('n', 'status');

        return response()->json([
            'accounts' => $monitor,
            'monthly' => $monthly,
            'hourly' => collect($buckets)->map(fn ($n, $h) => ['hour' => $h, 'count' => $n])->values(),
            'runs' => [
                'total' => $total, 'by_status' => $byStatus, 'completed' => (int) ($byStatus['done'] ?? 0),
                'failed' => (int) ($byStatus['failed'] ?? 0), 'waiting' => (int) ($byStatus['waiting'] ?? 0),
                'clicked' => $clicked, 'followed' => $followed, 'leads' => $leads,
                'completion_rate' => $total ? round(100 * ($byStatus['done'] ?? 0) / $total, 1) : null,
            ],
            'failure_reasons' => $reasons,
            'webhooks' => $events,
        ]);
    }
}
