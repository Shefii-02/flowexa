<?php

namespace App\Modules\Analytics\Repositories;

use App\Modules\Analytics\Repositories\Interfaces\AnalyticsRepositoryInterface;
use Illuminate\Support\Facades\DB;

class AnalyticsRepository implements AnalyticsRepositoryInterface
{
    // ─── Overview dashboard ───────────────────────────────────────────────────
    public function overview(int $companyId): array
    {
        $today     = now()->toDateString();
        $thisMonth = now()->startOfMonth()->toDateString();
        $lastMonth = now()->subMonth()->startOfMonth()->toDateString();
        $lastMonthEnd = now()->subMonth()->endOfMonth()->toDateString();

        $contacts = DB::table('contacts')->where('company_id', $companyId);
        $leads    = DB::table('leads')->where('company_id', $companyId)->whereNull('deleted_at');
        $messages = DB::table('message_logs')->where('company_id', $companyId);
        $wallet   = DB::table('wallets')->where('company_id', $companyId)->first();

        return [
            'contacts' => [
                'total'       => (clone $contacts)->count(),
                'opted_in'    => (clone $contacts)->where('opted_in', true)->count(),
                'new_today'   => (clone $contacts)->whereDate('created_at', $today)->count(),
                'new_month'   => (clone $contacts)->where('created_at', '>=', $thisMonth)->count(),
            ],
            'leads' => [
                'total'           => (clone $leads)->count(),
                'new'             => (clone $leads)->where('stage', 'new')->count(),
                'in_progress'     => (clone $leads)->whereIn('stage', ['contacted','follow_up'])->count(),
                'enrolled'        => (clone $leads)->where('stage', 'enrolled')->count(),
                'lost'            => (clone $leads)->where('stage', 'lost')->count(),
                'new_today'       => (clone $leads)->whereDate('created_at', $today)->count(),
                'conversion_rate' => $this->conversionRate($companyId),
            ],
            'messages' => [
                'total_sent'      => (clone $messages)->where('direction','outbound')->count(),
                'sent_today'      => (clone $messages)->where('direction','outbound')->whereDate('created_at',$today)->count(),
                'sent_this_month' => (clone $messages)->where('direction','outbound')->where('created_at','>=',$thisMonth)->count(),
                'inbound_total'   => (clone $messages)->where('direction','inbound')->count(),
            ],
            'wallet' => [
                'balance'         => $wallet->balance           ?? 0,
                'total_used'      => $wallet->total_used        ?? 0,
                'total_purchased' => $wallet->total_purchased   ?? 0,
            ],
            'campaigns' => [
                'total'     => DB::table('campaigns')->where('company_id', $companyId)->whereNull('deleted_at')->count(),
                'running'   => DB::table('campaigns')->where('company_id', $companyId)->where('status','running')->count(),
                'completed' => DB::table('campaigns')->where('company_id', $companyId)->where('status','completed')->count(),
            ],
        ];
    }

    // ─── Campaign analytics ───────────────────────────────────────────────────
    public function campaigns(int $companyId): array
    {
        $campaigns = DB::table('campaigns')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->whereNotIn('status', ['draft'])
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        $byStatus = DB::table('campaigns')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total','status');

        $monthly = DB::table('campaigns')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->where('created_at', '>=', now()->subMonths(6))
            ->select(
                DB::raw("DATE_FORMAT(created_at, '%Y-%m') as month"),
                DB::raw('count(*) as total'),
                DB::raw('sum(total_contacts) as messages_sent'),
                DB::raw('sum(delivered) as delivered'),
                DB::raw('sum(read) as total_read')
            )
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        return [
            'recent'   => $campaigns->map(fn($c) => [
                'id'            => $c->id,
                'name'          => $c->name,
                'status'        => $c->status,
                'total_contacts'=> $c->total_contacts,
                'delivered'     => $c->delivered,
                'read'          => $c->read,
                'failed'        => $c->failed,
                'delivery_rate' => $c->total_contacts > 0 ? round(($c->delivered / $c->total_contacts) * 100, 1) : 0,
                'read_rate'     => $c->total_contacts > 0 ? round(($c->read / $c->total_contacts) * 100, 1) : 0,
                'created_at'    => $c->created_at,
            ]),
            'by_status'  => $byStatus,
            'monthly'    => $monthly,
        ];
    }

    // ─── Flow analytics ───────────────────────────────────────────────────────
    public function flows(int $companyId): array
    {
        $nodes = DB::table('flow_nodes')
            ->where('company_id', $companyId)
            ->orderByDesc('trigger_count')
            ->get();

        $total = $nodes->sum('trigger_count');

        return [
            'total_nodes'    => $nodes->count(),
            'active_nodes'   => $nodes->where('is_active', true)->count(),
            'total_triggers' => $total,
            'top_nodes'      => $nodes->take(10)->map(fn($n) => [
                'id'            => $n->id,
                'title'         => $n->title,
                'type'          => $n->type,
                'lead_category' => $n->lead_category,
                'trigger_count' => $n->trigger_count,
                'share_percent' => $total > 0 ? round(($n->trigger_count / $total) * 100, 1) : 0,
                'is_active'     => $n->is_active,
            ])->values(),
            'by_type' => $nodes->groupBy('type')->map(fn($g) => [
                'count'    => $g->count(),
                'triggers' => $g->sum('trigger_count'),
            ]),
            'by_category' => $nodes->whereNotNull('lead_category')
                ->groupBy('lead_category')
                ->map(fn($g) => ['count' => $g->count(), 'triggers' => $g->sum('trigger_count')]),
        ];
    }

    // ─── Staff performance ────────────────────────────────────────────────────
    public function staff(int $companyId): array
    {
        $staff = DB::table('users as u')
            ->join('roles as r', 'u.role_id', '=', 'r.id')
            ->where('u.company_id', $companyId)
            ->whereIn('r.name', ['counsellor','team_lead','admin'])
            ->select('u.id','u.name','u.email','u.department','u.max_leads','r.label as role')
            ->get();

        $staffData = $staff->map(function ($s) {
            $leads = DB::table('leads')
                ->where('assigned_to', $s->id)
                ->whereNull('deleted_at')
                ->select('stage', DB::raw('count(*) as total'))
                ->groupBy('stage')
                ->pluck('total','stage');

            $total    = $leads->sum();
            $active   = ($leads['new'] ?? 0) + ($leads['contacted'] ?? 0) + ($leads['follow_up'] ?? 0);
            $enrolled = $leads['enrolled'] ?? 0;

            return [
                'id'               => $s->id,
                'name'             => $s->name,
                'email'            => $s->email,
                'department'       => $s->department,
                'role'             => $s->role,
                'max_leads'        => $s->max_leads,
                'leads' => [
                    'total'      => $total,
                    'new'        => $leads['new']        ?? 0,
                    'contacted'  => $leads['contacted']  ?? 0,
                    'follow_up'  => $leads['follow_up']  ?? 0,
                    'enrolled'   => $enrolled,
                    'lost'       => $leads['lost']       ?? 0,
                    'active'     => $active,
                ],
                'conversion_rate'  => $total > 0 ? round(($enrolled / $total) * 100, 1) : 0,
                'capacity_percent' => $s->max_leads > 0 ? round(($active / $s->max_leads) * 100, 1) : 0,
            ];
        });

        return [
            'staff'              => $staffData->values(),
            'top_converter'      => $staffData->sortByDesc('conversion_rate')->first(),
            'highest_capacity'   => $staffData->sortByDesc('capacity_percent')->first(),
        ];
    }

    // ─── Wallet analytics ─────────────────────────────────────────────────────
    public function wallet(int $companyId): array
    {
        $wallet = DB::table('wallets')->where('company_id', $companyId)->first();

        $monthly = DB::table('wallet_transactions')
            ->where('company_id', $companyId)
            ->where('created_at', '>=', now()->subMonths(6))
            ->select(
                DB::raw("DATE_FORMAT(created_at,'%Y-%m') as month"),
                'type',
                DB::raw('sum(amount) as total')
            )
            ->groupBy('month','type')
            ->orderBy('month')
            ->get()
            ->groupBy('month')
            ->map(fn($g) => [
                'credit' => $g->where('type','credit')->sum('total'),
                'debit'  => $g->where('type','debit')->sum('total'),
            ]);

        $byRefType = DB::table('wallet_transactions')
            ->where('company_id', $companyId)
            ->where('type', 'debit')
            ->select('reference_type', DB::raw('sum(amount) as total'))
            ->groupBy('reference_type')
            ->pluck('total','reference_type');

        $recentTx = DB::table('wallet_transactions')
            ->where('company_id', $companyId)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        return [
            'balance'         => $wallet->balance          ?? 0,
            'total_used'      => $wallet->total_used       ?? 0,
            'total_purchased' => $wallet->total_purchased  ?? 0,
            'burn_rate_daily' => $this->dailyBurnRate($companyId),
            'monthly'         => $monthly,
            'by_reference'    => $byRefType,
            'recent_transactions' => $recentTx,
        ];
    }

    // ─── Lead analytics ───────────────────────────────────────────────────────
    public function leads(int $companyId): array
    {
        $leads = DB::table('leads')->where('company_id', $companyId)->whereNull('deleted_at');

        $byStage    = (clone $leads)->select('stage', DB::raw('count(*) as total'))->groupBy('stage')->pluck('total','stage');
        $byCategory = (clone $leads)->select('category', DB::raw('count(*) as total'))->groupBy('category')->pluck('total','category');
        $bySource   = (clone $leads)->select('source', DB::raw('count(*) as total'))->groupBy('source')->pluck('total','source');
        $byPriority = (clone $leads)->select('priority', DB::raw('count(*) as total'))->groupBy('priority')->pluck('total','priority');

        $monthly = DB::table('leads')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->where('created_at', '>=', now()->subMonths(6))
            ->select(DB::raw("DATE_FORMAT(created_at,'%Y-%m') as month"), DB::raw('count(*) as total'))
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        $total = (clone $leads)->count();

        return [
            'total'           => $total,
            'conversion_rate' => $this->conversionRate($companyId),
            'by_stage'        => $byStage,
            'by_category'     => $byCategory,
            'by_source'       => $bySource,
            'by_priority'     => $byPriority,
            'monthly_new'     => $monthly,
        ];
    }

    // ─── Message analytics ────────────────────────────────────────────────────
    public function messages(int $companyId): array
    {
        $logs = DB::table('message_logs')->where('company_id', $companyId);

        $daily = DB::table('message_logs')
            ->where('company_id', $companyId)
            ->where('created_at', '>=', now()->subDays(30))
            ->select(
                DB::raw("DATE(created_at) as date"),
                'direction',
                DB::raw('count(*) as total')
            )
            ->groupBy('date','direction')
            ->orderBy('date')
            ->get()
            ->groupBy('date')
            ->map(fn($g) => [
                'inbound'  => $g->where('direction','inbound')->sum('total'),
                'outbound' => $g->where('direction','outbound')->sum('total'),
            ]);

        $byType = (clone $logs)->select('type', DB::raw('count(*) as total'))->groupBy('type')->pluck('total','type');
        $byStatus = (clone $logs)->whereNotNull('status')->select('status', DB::raw('count(*) as total'))->groupBy('status')->pluck('total','status');

        return [
            'total_inbound'  => (clone $logs)->where('direction','inbound')->count(),
            'total_outbound' => (clone $logs)->where('direction','outbound')->count(),
            'by_type'        => $byType,
            'by_status'      => $byStatus,
            'daily_30_days'  => $daily,
        ];
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────
    private function conversionRate(int $companyId): float
    {
        $total    = DB::table('leads')->where('company_id', $companyId)->whereNull('deleted_at')->count();
        $enrolled = DB::table('leads')->where('company_id', $companyId)->where('stage','enrolled')->whereNull('deleted_at')->count();
        return $total > 0 ? round(($enrolled / $total) * 100, 1) : 0.0;
    }

    private function dailyBurnRate(int $companyId): float
    {
        $used = DB::table('wallet_transactions')
            ->where('company_id', $companyId)
            ->where('type', 'debit')
            ->where('created_at', '>=', now()->subDays(30))
            ->sum('amount');
        return round($used / 30, 1);
    }
}
