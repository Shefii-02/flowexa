<?php

namespace App\Support;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Single source of truth for plan limits: what a company has used, what its plan allows, and whether one more
 * (or N more) may be added. The route middleware, controllers that add rows in bulk, and the /plan/usage endpoint
 * the UI reads all go through here, so the number the user sees is exactly the number the server enforces.
 *
 * A NULL limit on the plan means unlimited. A limit of 0 means none allowed.
 */
class PlanLimits
{
    /** key => [label, table, plan column, kind]  (kind: total = running count, monthly = created this calendar month) */
    public const REGISTRY = [
        'users'              => ['Staff users',       'users',              'max_users',              'total'],
        'roles'              => ['Roles',             'roles',              'max_roles',              'total'],
        'templates'          => ['Templates',         'wa_templates',       'max_templates',          'total'],
        'phone_numbers'      => ['Phone numbers',     'wa_phone_numbers',   'max_phone_numbers',      'total'],
        'wa_sessions'        => ['WhatsApp sessions', 'openwa_sessions',    'max_wa_sessions',        'total'],
        'campaigns'          => ['Campaigns',         'campaigns',          'max_campaigns',          'total'],
        'contacts'           => ['Contacts',          'contacts',           'max_contacts',           'total'],
        'labels'             => ['Labels',            'contact_labels',     'max_labels',             'total'],
        'flow_nodes'         => ['Flow nodes',        'flow_nodes',         'max_flow_nodes',         'total'],
        'lead_categories'    => ['Lead categories',   'lead_categories',    'max_lead_categories',    'total'],
        'instagram_accounts' => ['Instagram accounts', 'instagram_accounts', 'max_instagram_accounts', 'total'],
        'meta_ads_accounts'  => ['Meta Ads accounts', 'meta_ad_accounts',   'max_meta_ads_accounts',  'total'],
        'website_widgets'    => ['Website widgets',   'chat_widgets',       'max_website_widgets',    'total'],
        'leads'              => ['Leads this month',  'leads',              'max_leads_per_month',    'monthly'],
    ];

    private static array $hasColumn = [];

    private static function has(string $table, string $column): bool
    {
        return self::$hasColumn["$table.$column"] ??= Schema::hasTable($table) && Schema::hasColumn($table, $column);
    }

    public static function forgetCache(): void { self::$hasColumn = []; }

    public static function label(string $key): string { return self::REGISTRY[$key][0] ?? str_replace('_', ' ', $key); }

    /** How many the company has right now. Soft-deleted rows do not count (deleting frees the slot). */
    public static function used(int $companyId, string $key): int
    {
        [, $table, , $kind] = self::REGISTRY[$key];
        if (!Schema::hasTable($table)) { return 0; }
        $q = DB::table($table)->where('company_id', $companyId);
        if (self::has($table, 'deleted_at')) { $q->whereNull('deleted_at'); }
        if ($kind === 'monthly') { $q->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()]); }
        return (int) $q->count();
    }

    /** The plan's cap for a resource, or null when unlimited / no plan. */
    public static function limit(?object $plan, string $key): ?int
    {
        if (!$plan) { return null; }
        $v = $plan->{self::REGISTRY[$key][2]} ?? null;
        return $v === null ? null : (int) $v;
    }

    /** How many more can be added (null = unlimited). */
    public static function remaining(int $companyId, ?object $plan, string $key): ?int
    {
        $max = self::limit($plan, $key);
        return $max === null ? null : max(0, $max - self::used($companyId, $key));
    }

    /** Everything the UI needs: used / allowed / left / exceeded for every resource. */
    public static function usage(int $companyId, ?object $plan): array
    {
        $out = [];
        foreach (self::REGISTRY as $key => [$label, $table, , $kind]) {
            if (!Schema::hasTable($table)) { continue; }
            $used = self::used($companyId, $key);
            $max = self::limit($plan, $key);
            $out[$key] = ['key' => $key, 'label' => $label, 'kind' => $kind, 'used' => $used, 'limit' => $max,
                          'remaining' => $max === null ? null : max(0, $max - $used), 'exceeded' => $max !== null && $used >= $max,
                          'percent' => $max === null || $max === 0 ? ($max === 0 ? 100 : null) : min(100, (int) round($used / $max * 100))];
        }
        return $out;
    }

    /** null when `$adding` more would fit, otherwise the error payload shown to the user. */
    public static function violation(int $companyId, ?object $plan, string $key, int $adding = 1): ?array
    {
        $max = self::limit($plan, $key);
        if ($max === null) { return null; }
        $used = self::used($companyId, $key);
        if ($used + $adding <= $max) { return null; }

        $label = strtolower(self::label($key));
        $per = self::REGISTRY[$key][3] === 'monthly' ? ' per month' : '';
        $left = max(0, $max - $used);
        $message = $adding > 1 && $left > 0
            ? "Limit exceeded: your plan allows {$max} {$label}{$per} and you have {$used}. You can add {$left} more, not {$adding}. Upgrade your plan to add more."
            : "Limit exceeded: your plan allows {$max} {$label}{$per} and you have already used {$used}. Upgrade your plan to add more.";
        return ['message' => $message, 'error_code' => 'plan_limit_reached', 'resource' => $key, 'current' => $used, 'limit' => $max, 'remaining' => $left, 'adding' => $adding];
    }

    /** For controllers that add rows themselves (bulk imports): throws a ready-made 403 JSON response. */
    public static function assertCanAdd(int $companyId, ?object $plan, string $key, int $adding = 1): void
    {
        if ($v = self::violation($companyId, $plan, $key, $adding)) {
            throw new HttpResponseException(response()->json($v, 403));
        }
    }

    /** [companyId, plan] for the signed-in user, or null when no limit applies (super admin, no company, no plan). */
    public static function context(?object $user): ?array
    {
        if (!$user || (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin())) { return null; }
        $company = $user->company ?? null;
        return $company ? [(int) $company->id, $company->plan] : null;
    }
}
