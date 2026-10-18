<?php

namespace App\Modules\Settings\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\MessageLog;
use App\Models\Plan;
use App\Modules\Settings\DTOs\MessageLogFilterDTO;
use App\Modules\Settings\DTOs\SuperAdminCreateCompanyDTO;
use App\Modules\Settings\DTOs\TopUpDTO;
use App\Modules\Settings\DTOs\UpdateCompanyStatusDTO;
use App\Modules\Settings\DTOs\UpdateSettingsDTO;
use App\Modules\Settings\DTOs\WaCredentialsDTO;
use App\Modules\Settings\Http\Requests\SuperAdminCreateCompanyRequest;
use App\Modules\Settings\Http\Requests\TopUpRequest;
use App\Modules\Settings\Http\Requests\UpdateCompanyStatusRequest;
use App\Modules\Settings\Http\Requests\UpdateSettingsRequest;
use App\Modules\Settings\Http\Requests\WaCredentialsRequest;
use App\Modules\Settings\Services\SettingsService;
use App\Modules\Settings\Services\SuperAdminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;


// ─── Message Log Controller ───────────────────────────────────────────────────
class MessageLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filter = MessageLogFilterDTO::fromRequest($request->all());

        $logs = MessageLog::where('company_id', auth()->user()->company_id)
            ->with('contact:id,name,phone')
            ->when($filter->direction, fn($q) => $q->where('direction', $filter->direction))
            ->when($filter->type,      fn($q) => $q->where('type',      $filter->type))
            ->when($filter->status,    fn($q) => $q->where('status',    $filter->status))
            ->when($filter->channel,   fn($q) => $q->where('channel',   $filter->channel))
            ->when($filter->source,    fn($q) => $q->where('source',    $filter->source))
            ->when($filter->phone,     fn($q) => $q->where('phone', 'like', "%{$filter->phone}%"))
            ->when($filter->search, fn($q) => $q->where(fn($w) => $w
                ->where('phone', 'like', "%{$filter->search}%")
                ->orWhere('content', 'like', "%{$filter->search}%")
                ->orWhereHas('contact', fn($c) => $c->where('name', 'like', "%{$filter->search}%"))))
            ->when($filter->from, fn($q) => $q->whereDate('created_at', '>=', $filter->from))
            ->when($filter->to,   fn($q) => $q->whereDate('created_at', '<=', $filter->to))
            ->latest()
            ->paginate($filter->perPage, ['*'], 'page', $filter->page);

        return response()->json($logs);

        //     $logs = MessageLog::with('contact')
//         ->where('company_id', auth()->user()->company_id)
//         ->when($request->direction, fn($q) => $q->where('direction', $request->direction))
//         ->when($request->contact_id, fn($q) => $q->where('contact_id', $request->contact_id))
//         ->when($request->from, fn($q) => $q->whereDate('created_at', '>=', $request->from))
//         ->when($request->to,   fn($q) => $q->whereDate('created_at', '<=', $request->to))
//         ->latest()
//         ->paginate(50);
//     return response()->json($logs);

    }

    /**
     * Chart + cost data for the Message Logs page.
     * range: today | yesterday | 7d | last_month | custom (+ from/to). Cost = delivered/read
     * template messages × the per-category rate in config/meta_pricing.php.
     */
    public function stats(Request $request): JsonResponse
    {
        [$from, $to] = $this->resolveRange($request);
        $filter = MessageLogFilterDTO::fromRequest($request->all());

        $base = fn () => MessageLog::where('company_id', auth()->user()->company_id)
            ->when($filter->channel,   fn ($q) => $q->where('channel', $filter->channel))
            ->when($filter->source,    fn ($q) => $q->where('source', $filter->source))
            ->when($filter->direction, fn ($q) => $q->where('direction', $filter->direction))
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()]);

        $delivered = "status IN ('delivered','read')";

        $rows = $base()->selectRaw("DATE(created_at) d,
                SUM(direction = 'inbound') inbound,
                SUM(direction = 'outbound' AND status <> 'failed') sent,
                SUM(direction = 'outbound' AND {$delivered}) delivered,
                SUM(direction = 'outbound' AND status = 'read') `read`,
                SUM(direction = 'outbound' AND status = 'failed') failed")
            ->groupBy('d')->get()->keyBy(fn ($r) => (string) $r->d);

        $series = [];
        for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
            $k = $day->toDateString();
            $r = $rows[$k] ?? null;
            $series[] = [
                'date'      => $k,
                'inbound'   => (int) ($r->inbound ?? 0),
                'sent'      => (int) ($r->sent ?? 0),
                'delivered' => (int) ($r->delivered ?? 0),
                'read'      => (int) ($r->read ?? 0),
                'failed'    => (int) ($r->failed ?? 0),
            ];
        }

        $rates    = config('meta_pricing.rates');
        $billable = $base()->where('direction', 'outbound')->where('type', 'template')
            ->whereIn('status', ['delivered', 'read'])
            ->selectRaw("COALESCE(source,'other') source, COALESCE(category,'unknown') category, COUNT(*) c")
            ->groupBy('source', 'category')->get();

        $byCategory = [];
        $bySource   = [];
        foreach ($billable as $b) {
            $cost = $b->c * ($rates[$b->category] ?? 0);
            $byCategory[$b->category] ??= ['category' => $b->category, 'count' => 0, 'rate' => $rates[$b->category] ?? 0, 'cost' => 0];
            $byCategory[$b->category]['count'] += $b->c;
            $byCategory[$b->category]['cost']  += $cost;
            $bySource[$b->source] = ($bySource[$b->source] ?? 0) + $cost;
        }

        $sourceCounts = $base()->selectRaw("COALESCE(source,'other') source, COUNT(*) total,
                SUM(direction = 'outbound' AND status = 'failed') failed")
            ->groupBy('source')->get()->map(fn ($r) => [
                'source' => $r->source, 'total' => (int) $r->total, 'failed' => (int) $r->failed,
                'cost'   => round($bySource[$r->source] ?? 0, 2),
            ])->values();

        $totals = [
            'inbound'   => array_sum(array_column($series, 'inbound')),
            'sent'      => array_sum(array_column($series, 'sent')),
            'delivered' => array_sum(array_column($series, 'delivered')),
            'read'      => array_sum(array_column($series, 'read')),
            'failed'    => array_sum(array_column($series, 'failed')),
        ];

        return response()->json([
            'from'      => $from->toDateString(),
            'to'        => $to->toDateString(),
            'series'    => $series,
            'totals'    => $totals,
            'sources'   => $sourceCounts,
            'cost'      => [
                'currency'    => config('meta_pricing.currency'),
                'total'       => round(array_sum(array_column($byCategory, 'cost')), 2),
                'by_category' => array_values(array_map(fn ($c) => $c + ['cost' => round($c['cost'], 2)], $byCategory)),
            ],
        ]);
    }

    /** @return array{0: \Carbon\Carbon, 1: \Carbon\Carbon} */
    private function resolveRange(Request $request): array
    {
        $today = now()->startOfDay();

        return match ($request->input('range', '7d')) {
            'today'      => [$today->copy(), $today->copy()],
            'yesterday'  => [$today->copy()->subDay(), $today->copy()->subDay()],
            'last_month' => [$today->copy()->subMonthNoOverflow()->startOfMonth(), $today->copy()->subMonthNoOverflow()->endOfMonth()->startOfDay()],
            'custom'     => (function () use ($request, $today) {
                $f = \Carbon\Carbon::parse($request->input('from', $today))->startOfDay();
                $t = \Carbon\Carbon::parse($request->input('to', $today))->startOfDay();
                if ($t->lt($f)) { [$f, $t] = [$t, $f]; }
                if ($f->diffInDays($t) > 92) { $f = $t->copy()->subDays(92); } // keep the chart bounded
                return [$f, $t];
            })(),
            default      => [$today->copy()->subDays(6), $today->copy()],
        };
    }

    public function show(MessageLog $log): JsonResponse
    {
        abort_if($log->company_id !== auth()->user()->company_id, 403);
        return response()->json(['log' => $log]);
    }
}
