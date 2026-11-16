<?php

namespace App\Modules\Analytics\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Analytics\Services\AnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AnalyticsController extends Controller
{
    public function __construct(private readonly AnalyticsService $analyticsService) {}

    // GET /v1/analytics/overview
    public function overview(): JsonResponse
    {
        return response()->json(
            $this->analyticsService->overview(auth()->user()->company_id)
        );
    }

    // GET /v1/analytics/campaigns
    public function campaigns(): JsonResponse
    {
        $this->authorizeViewAll();
        return response()->json(
            $this->analyticsService->campaigns(auth()->user()->company_id)
        );
    }

    // GET /v1/analytics/flows
    public function flows(): JsonResponse
    {
        $this->authorizeViewAll();
        return response()->json(
            $this->analyticsService->flows(auth()->user()->company_id)
        );
    }



    // GET /v1/analytics/staff
    public function staff(): JsonResponse
    {
        Log::info('Fetching staff analytics.');
        $this->authorizeViewAll();
        return response()->json(
            $this->analyticsService->staff(auth()->user()->company_id)
        );
    }

    // GET /v1/analytics/wallet
    public function wallet(): JsonResponse
    {
        abort_unless(auth()->user()->hasPermission('billing.view'), 403, 'No permission.');
        return response()->json(
            $this->analyticsService->wallet(auth()->user()->company_id)
        );
    }

    // GET /v1/analytics/wa-cloud-home?range=this_month|last_month|last_3_months
    // Company-admin Home: plan usage (templates / campaigns / this-month campaigns) + a daily series for the chart.
    public function waCloudHome(Request $request): JsonResponse
    {
        $user = auth()->user();
        $cid  = (int) $user->company_id;
        $range = $request->query('range', 'this_month');
        [$from, $to] = match ($range) {
            'today'         => [now()->startOfDay(), now()->endOfDay()],
            'last_week'     => [now()->subWeek()->startOfWeek(), now()->subWeek()->endOfWeek()],
            'last_month'    => [now()->subMonthNoOverflow()->startOfMonth(), now()->subMonthNoOverflow()->endOfMonth()],
            'last_3_months' => [now()->subMonthsNoOverflow(2)->startOfMonth(), now()->endOfDay()],
            default         => [now()->startOfMonth(), now()->endOfDay()],
        };

        $usage = \App\Support\PlanLimits::usage($cid, $user->company?->plan);
        $u = fn (string $k) => ['used' => $usage[$k]['used'] ?? 0, 'limit' => $usage[$k]['limit'] ?? null];

        $monthStart = now()->startOfMonth();
        $camp = \DB::table('campaigns')->where('company_id', $cid);
        $month = [
            'running'   => (clone $camp)->where('status', 'running')->count(),
            'completed' => (clone $camp)->where('status', 'completed')->where('created_at', '>=', $monthStart)->count(),
        ];

        // One row per day in range, so the chart has a continuous x axis.
        $days = [];
        $hourly = $range === 'today';
        $bucket = $hourly ? "DATE_FORMAT(created_at,'%Y-%m-%d %H')" : 'DATE(created_at)';
        for ($d = $from->copy()->startOfDay(); $d <= $to; $hourly ? $d->addHour() : $d->addDay()) { $days[$hourly ? $d->format('Y-m-d H') : $d->toDateString()] = ['incoming' => 0, 'outgoing' => 0, 'campaigns' => 0, 'leads' => 0, 'converted' => 0, 'rejected' => 0]; }
        $fill = function ($rows, string $key) use (&$days) { foreach ($rows as $r) { if (isset($days[$r->d])) { $days[$r->d][$key] = (int) $r->c; } } };

        $count = fn ($q) => $q->select(\DB::raw("$bucket as d"), \DB::raw('count(*) as c'))->groupBy('d')->get();
        $msgs = \DB::table('message_logs')->where('company_id', $cid)->whereBetween('created_at', [$from, $to]);
        $fill($count((clone $msgs)->where('direction', 'inbound')), 'incoming');
        $fill($count((clone $msgs)->where('direction', 'outbound')), 'outgoing');
        $fill($count(\DB::table('campaigns')->where('company_id', $cid)->whereBetween('created_at', [$from, $to])), 'campaigns');
        $leads = \DB::table('leads')->where('company_id', $cid)->whereNull('deleted_at')->whereBetween('created_at', [$from, $to]);
        $fill($count(clone $leads), 'leads');
        $fill($count((clone $leads)->where('stage', 'enrolled')), 'converted');
        $fill($count((clone $leads)->where('stage', 'lost')), 'rejected');

        $series = [];
        foreach ($days as $date => $v) { $series[] = ['date' => $date] + $v; }

        return response()->json([
            'range'     => $range,
            'templates' => $u('templates'),
            'campaigns' => $u('campaigns'),
            'campaigns_month' => $u('campaigns_month') + $month,
            'series'    => $series,
            'totals'    => [
                'incoming'  => array_sum(array_column($series, 'incoming')),
                'outgoing'  => array_sum(array_column($series, 'outgoing')),
                'campaigns' => array_sum(array_column($series, 'campaigns')),
                'leads'     => array_sum(array_column($series, 'leads')),
                'converted' => array_sum(array_column($series, 'converted')),
                'rejected'  => array_sum(array_column($series, 'rejected')),
            ],
        ]);
    }

    // GET /v1/analytics/leads-home?range=this_month|last_month|last_3_months
    // Daily series: leads created, converted (enrolled), closed (enrolled or lost), rejected (lost),
    // pending (open: new/contacted) and follow-ups (stage follow_up).
    public function leadsHome(Request $request): JsonResponse
    {
        $cid = (int) auth()->user()->company_id;
        $range = $request->query('range', 'this_month');
        [$from, $to] = match ($range) {
            'today'         => [now()->startOfDay(), now()->endOfDay()],
            'last_week'     => [now()->subWeek()->startOfWeek(), now()->subWeek()->endOfWeek()],
            'last_month'    => [now()->subMonthNoOverflow()->startOfMonth(), now()->subMonthNoOverflow()->endOfMonth()],
            'last_3_months' => [now()->subMonthsNoOverflow(2)->startOfMonth(), now()->endOfDay()],
            default         => [now()->startOfMonth(), now()->endOfDay()],
        };
        $keys = ['leads', 'converted', 'closed', 'rejected', 'pending', 'followups'];
        $days = [];
        $hourly = $range === 'today';
        for ($d = $from->copy()->startOfDay(); $d <= $to; $hourly ? $d->addHour() : $d->addDay()) { $days[$hourly ? $d->format('Y-m-d H') : $d->toDateString()] = array_fill_keys($keys, 0); }
        $base = fn () => \DB::table('leads')->where('company_id', $cid)->whereNull('deleted_at');
        $fill = function ($q, string $col, string $key) use (&$days, $from, $to, $hourly) {
            $expr = $hourly ? "DATE_FORMAT($col,'%Y-%m-%d %H')" : "DATE($col)";
            foreach ($q->whereBetween($col, [$from, $to])->select(\DB::raw("$expr as d"), \DB::raw('count(*) as c'))->groupBy('d')->get() as $r) {
                if (isset($days[$r->d])) { $days[$r->d][$key] += (int) $r->c; }
            }
        };
        $fill($base(), 'created_at', 'leads');
        $fill($base()->where('stage', 'enrolled'), 'enrolled_at', 'converted');
        $fill($base()->where('stage', 'enrolled'), 'enrolled_at', 'closed');
        $fill($base()->where('stage', 'lost'), 'lost_at', 'closed');
        $fill($base()->where('stage', 'lost'), 'lost_at', 'rejected');
        $fill($base()->whereIn('stage', ['new', 'contacted']), 'created_at', 'pending');
        $fill($base()->where('stage', 'follow_up'), 'created_at', 'followups');

        $series = [];
        foreach ($days as $date => $v) { $series[] = ['date' => $date] + $v; }
        $totals = [];
        foreach ($keys as $k) { $totals[$k] = array_sum(array_column($series, $k)); }
        return response()->json(['range' => $range, 'series' => $series, 'totals' => $totals]);
    }

    // GET /v1/analytics/leads
    public function leads(): JsonResponse
    {
        return response()->json(
            $this->analyticsService->leads(auth()->user()->company_id)
        );
    }

    // GET /v1/analytics/messages
    public function messages(): JsonResponse
    {
        $this->authorizeViewAll();
        return response()->json(
            $this->analyticsService->messages(auth()->user()->company_id)
        );
    }

    private function authorizeViewAll(): void
    {
        abort_unless(
            auth()->user()->hasAnyPermission(['analytics.view_all', 'analytics.view_own']),
            403,
            'No analytics permission.'
        );
    }
}
