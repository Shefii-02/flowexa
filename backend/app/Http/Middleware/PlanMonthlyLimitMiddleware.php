<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * PlanMonthlyLimitMiddleware
 * Usage in routes: middleware('plan.monthly_limit:leads')
 *
 * Same shape as PlanLimitMiddleware, but counts only rows created in the current
 * calendar month instead of all-time — for limits that reset every month (e.g.
 * "leads created this month") rather than a running total (e.g. "total contacts").
 */
class PlanMonthlyLimitMiddleware
{
    // Map resource names → [table, company_fk, plan_limit_column]
    public const LIMITS = [
        'leads' => ['leads', 'company_id', 'max_leads_per_month'],
    ];

    public function handle(Request $request, Closure $next, string $resource): mixed
    {
        $user = auth()->user();
        if (!$user || $user->isSuperAdmin()) {
            return $next($request);
        }

        $company = $user->company;
        if (!$company) return $next($request);

        $plan = $company->plan;
        if (!$plan) return $next($request);

        $limitKey = self::LIMITS[$resource] ?? null;
        if (!$limitKey) return $next($request);

        [$table, $fk, $planColumn] = $limitKey;
        $max = $plan->$planColumn;

        // null = unlimited
        if ($max === null) return $next($request);

        $current = DB::table($table)
            ->where($fk, $company->id)
            ->whereYear('created_at', now()->year)
            ->whereMonth('created_at', now()->month)
            ->count();

        if ($current >= $max) {
            $label = str_replace('_', ' ', $resource);
            return response()->json([
                'message'    => "Your plan allows {$max} {$label} per month. Upgrade your plan to add more.",
                'error_code' => 'plan_limit_reached',
                'resource'   => $resource,
                'current'    => $current,
                'limit'      => $max,
            ], 403);
        }

        return $next($request);
    }
}
