<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * PlanFeatureMiddleware
 * Usage in routes: middleware('plan.feature:google_sheets')
 *
 * Boolean on/off gate for whole integrations, as opposed to PlanLimitMiddleware's
 * count-vs-max checks. A company with no plan (or a superadmin) is never gated —
 * same "no plan = unrestricted" convention PlanLimitMiddleware uses.
 */
class PlanFeatureMiddleware
{
    // Map feature names → Plan boolean column
    public const FEATURES = [
        'google_sheets'      => 'google_sheets_enabled',
        'google_drive'       => 'google_drive_enabled',
        'calendar'           => 'calendar_enabled',
        'email_integration'  => 'email_integration_enabled',
    ];

    public function handle(Request $request, Closure $next, string $feature): mixed
    {
        $user = auth()->user();
        if (!$user || $user->isSuperAdmin()) {
            return $next($request);
        }

        $company = $user->company;
        if (!$company) return $next($request);

        $plan = $company->plan;
        if (!$plan) return $next($request);

        $column = self::FEATURES[$feature] ?? null;
        if (!$column) return $next($request);

        if (!$plan->$column) {
            $label = str_replace('_', ' ', $feature);
            return response()->json([
                'message'    => "The {$label} integration is not available on your plan. Upgrade your plan to enable it.",
                'error_code' => 'plan_feature_disabled',
                'feature'    => $feature,
            ], 403);
        }

        return $next($request);
    }
}
