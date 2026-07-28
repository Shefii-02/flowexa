<?php

namespace App\Http\Middleware;

use App\Models\Company;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * PlanLimitMiddleware
 * Usage in routes: middleware('plan.limit:contacts')
 * Checks current company usage against plan limits before allowing create actions.
 */
class PlanLimitMiddleware
{
    // Map resource names → [table, company_fk, count_column]
    private const LIMITS = [
        'users'           => ['users',           'company_id', 'max_users'],
        'templates'       => ['wa_templates',     'company_id', 'max_templates'],
        'phone_numbers'   => ['wa_phone_numbers', 'company_id', 'max_phone_numbers'],
        'campaigns'       => ['campaigns',        'company_id', 'max_campaigns'],
        'contacts'        => ['contacts',         'company_id', 'max_contacts'],
        'labels'          => ['contact_labels',   'company_id', 'max_labels'],
        'flow_nodes'      => ['flow_nodes',       'company_id', 'max_flow_nodes'],
    ];

    public function handle(Request $request, Closure $next, string $resource): mixed
    {
        $user    = auth()->user();

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

        $current = DB::table($table)->where($fk, $company->id)->count();

        if ($current >= $max) {
            return response()->json([
                'message'    => "You have reached your plan limit of {$max} {$resource}. Please upgrade your plan.",
                'error_code' => 'plan_limit_reached',
                'resource'   => $resource,
                'current'    => $current,
                'limit'      => $max,
            ], 403);
        }

        return $next($request);
    }
}
