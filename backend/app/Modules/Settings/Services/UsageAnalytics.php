<?php

namespace App\Modules\Settings\Services;

use App\Models\ApiRequestLog;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Superadmin → Usage analytics: how each company uses the web portal vs the mobile app, which
 * modules (pages) they use, who is active. Built from api_request_logs (kept ~30 days by logs:prune).
 */
class UsageAnalytics
{
    /** Module a request belongs to: first segment of the route name ("leads.index" → leads), else of the path (/api/v1/leads/… → leads). */
    private const MODULE_SQL = "COALESCE(NULLIF(SUBSTRING_INDEX(route_name, '.', 1), ''), SUBSTRING_INDEX(SUBSTRING_INDEX(path, '/', 4), '/', -1))";

    private function base(int $days, ?int $companyId = null)
    {
        return ApiRequestLog::query()->whereNotNull('company_id')
            ->where('created_at', '>=', now()->subDays($days)->startOfDay())
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId));
    }

    public function overview(int $days = 7): array
    {
        $days = max(1, min(30, $days));
        $b = fn () => $this->base($days);

        $split = $b()->selectRaw("client, COUNT(*) req, COUNT(DISTINCT user_id) users, COUNT(DISTINCT company_id) companies")->groupBy('client')->get()->keyBy('client');
        $tot = fn ($c) => ['requests' => (int) ($split[$c]->req ?? 0), 'users' => (int) ($split[$c]->users ?? 0), 'companies' => (int) ($split[$c]->companies ?? 0)];

        $perCompany = $b()->selectRaw("company_id, COUNT(*) req, SUM(client='portal') portal, SUM(client='app') app,
                COUNT(DISTINCT user_id) users, COUNT(DISTINCT CASE WHEN client='portal' THEN user_id END) portal_users,
                COUNT(DISTINCT CASE WHEN client='app' THEN user_id END) app_users,
                SUM(is_error) errors, AVG(duration_ms) avg_ms, MAX(created_at) last_active")
            ->groupBy('company_id')->get()->keyBy('company_id');

        $topModules = $b()->selectRaw(self::MODULE_SQL . " module, company_id, COUNT(*) c")
            ->groupBy('module', 'company_id')->get()->groupBy('company_id')
            ->map(fn ($rows) => $rows->sortByDesc('c')->take(3)->map(fn ($r) => ['module' => $r->module, 'count' => (int) $r->c])->values());

        $companies = Company::select('id', 'name', 'status')->orderBy('name')->get()->map(function ($c) use ($perCompany, $topModules) {
            $r = $perCompany->get($c->id);
            return [
                'id' => $c->id, 'name' => $c->name, 'status' => $c->status,
                'requests' => (int) ($r->req ?? 0), 'portal_requests' => (int) ($r->portal ?? 0), 'app_requests' => (int) ($r->app ?? 0),
                'active_users' => (int) ($r->users ?? 0), 'portal_users' => (int) ($r->portal_users ?? 0), 'app_users' => (int) ($r->app_users ?? 0),
                'errors' => (int) ($r->errors ?? 0), 'avg_ms' => round((float) ($r->avg_ms ?? 0), 1),
                'last_active_at' => $r?->last_active ? \Illuminate\Support\Carbon::parse($r->last_active)->toIso8601String() : null,
                'top_modules' => $topModules->get($c->id, collect())->all(),
                'total_users' => User::where('company_id', $c->id)->where('is_active', true)->count(),
            ];
        })->sortByDesc('requests')->values();

        return [
            'days' => $days,
            'summary' => [
                'companies_total' => $companies->count(),
                'companies_active' => $companies->where('requests', '>', 0)->count(),
                'portal' => $tot('portal'), 'app' => $tot('app'),
            ],
            'by_day' => $this->byDay($days, null),
            'modules' => $this->modules($days, null),
            'companies' => $companies,
        ];
    }

    public function company(int $companyId, int $days = 7): array
    {
        $days = max(1, min(30, $days));
        $users = $this->base($days, $companyId)
            ->selectRaw("user_id, COUNT(*) req, SUM(client='portal') portal, SUM(client='app') app, MAX(created_at) last_active, SUM(is_error) errors")
            ->groupBy('user_id')->orderByDesc('req')->get();
        $names = User::whereIn('id', $users->pluck('user_id'))->with('role:id,name')->get()->keyBy('id');

        $devices = \Illuminate\Support\Facades\Schema::hasTable('user_devices')
            ? DB::table('user_devices')->where('company_id', $companyId)->whereNull('revoked_at')
                ->selectRaw('platform, COUNT(*) c')->groupBy('platform')->pluck('c', 'platform') : collect();

        return [
            'days' => $days,
            'users' => $users->map(fn ($r) => [
                'user_id' => $r->user_id, 'name' => $names[$r->user_id]->name ?? ('#' . $r->user_id), 'role' => $names[$r->user_id]->role->name ?? null,
                'requests' => (int) $r->req, 'portal_requests' => (int) $r->portal, 'app_requests' => (int) $r->app,
                'errors' => (int) $r->errors, 'last_active_at' => \Illuminate\Support\Carbon::parse($r->last_active)->toIso8601String(),
            ])->values(),
            'by_day' => $this->byDay($days, $companyId),
            'modules' => $this->modules($days, $companyId),
            'linked_devices' => $devices,
        ];
    }

    private function byDay(int $days, ?int $companyId)
    {
        return $this->base($days, $companyId)
            ->selectRaw("DATE(created_at) day, SUM(client='portal') portal, SUM(client='app') app, COUNT(DISTINCT user_id) users")
            ->groupBy('day')->orderBy('day')->get()
            ->map(fn ($r) => ['day' => $r->day, 'portal' => (int) $r->portal, 'app' => (int) $r->app, 'users' => (int) $r->users]);
    }

    private function modules(int $days, ?int $companyId)
    {
        return $this->base($days, $companyId)
            ->selectRaw(self::MODULE_SQL . " module, COUNT(*) total, SUM(client='portal') portal, SUM(client='app') app, COUNT(DISTINCT user_id) users, AVG(duration_ms) avg_ms")
            ->groupBy('module')->orderByDesc('total')->limit(25)->get()
            ->map(fn ($r) => ['module' => $r->module ?: 'other', 'total' => (int) $r->total, 'portal' => (int) $r->portal, 'app' => (int) $r->app, 'users' => (int) $r->users, 'avg_ms' => round((float) $r->avg_ms, 1)]);
    }
}
