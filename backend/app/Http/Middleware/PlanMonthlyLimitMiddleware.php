<?php

namespace App\Http\Middleware;

use App\Support\PlanLimits;
use Closure;
use Illuminate\Http\Request;

/**
 * Usage in routes: middleware('plan.monthly_limit:leads')
 * Same as plan.limit, for resources that reset every calendar month (e.g. leads created this month).
 */
class PlanMonthlyLimitMiddleware
{
    public const LIMITS = [];

    public function handle(Request $request, Closure $next, string $resource): mixed
    {
        $ctx = PlanLimits::context(auth()->user());
        if (!$ctx || (PlanLimits::REGISTRY[$resource][3] ?? null) !== 'monthly') { return $next($request); }
        [$companyId, $plan] = $ctx;

        $violation = PlanLimits::violation($companyId, $plan, $resource);
        return $violation ? response()->json($violation, 403) : $next($request);
    }
}
