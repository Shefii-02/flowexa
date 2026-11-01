<?php

namespace App\Http\Middleware;

use App\Support\PlanLimits;
use Closure;
use Illuminate\Http\Request;

/**
 * Usage in routes: middleware('plan.limit:contacts')
 * Refuses a create when the company has already reached its plan's cap for that resource (see PlanLimits).
 */
class PlanLimitMiddleware
{
    /** Kept for callers that read the map; the single definition lives in PlanLimits::REGISTRY. */
    public const LIMITS = [];

    public function handle(Request $request, Closure $next, string $resource): mixed
    {
        $ctx = PlanLimits::context(auth()->user());
        if (!$ctx || !isset(PlanLimits::REGISTRY[$resource])) { return $next($request); }
        [$companyId, $plan] = $ctx;

        $violation = PlanLimits::violation($companyId, $plan, $resource);
        return $violation ? response()->json($violation, 403) : $next($request);
    }
}
