<?php

namespace App\Support;

use App\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a company may use: the modules its plan includes plus any it bought as add-ons.
 * A company with no plan (trial) and the super admin are never restricted — same convention as PlanLimits.
 */
class Entitlements
{
    private static array $memo = [];

    public static function forget(): void { self::$memo = []; }

    /** @return array{all:bool, modules:string[], from_plan:string[], from_addons:string[], locked:array<int,array>, assign_methods:string[]} */
    public static function forCompany(?Company $company): array
    {
        if (!$company || !$company->plan_id || !$company->plan) {
            return self::open();
        }
        $hit = self::$memo[$company->id] ?? null;
        if ($hit && time() - $hit['t'] <= 5) { return $hit['d']; }

        $plan = $company->plan;
        $fromPlan = $plan->enabledModules();
        $fromAddons = self::addonModules($company->id);
        $comp = self::complimentary($company, $plan);          // module => expires_at (still running)
        $disabled = self::disabled($company->id);              // switched off by the super admin for this company
        $modules = array_values(array_diff(array_unique(array_merge($fromPlan, $fromAddons, array_keys($comp))), $disabled));
        $locked = array_values(array_filter(array_map(fn ($k) => in_array($k, $modules, true) ? null : Modules::get($k), Modules::keys())));

        $d = [
            'all' => false, 'modules' => $modules, 'from_plan' => $fromPlan, 'from_addons' => array_values(array_diff($fromAddons, $fromPlan)),
            'from_complimentary' => array_values(array_diff(array_keys($comp), $fromPlan, $fromAddons)),
            'complimentary' => $comp, 'disabled' => $disabled,
            'locked' => $locked,
            'assign_methods' => $plan->lead_assign_methods === null ? array_keys(Modules::ASSIGN_METHODS) : array_values($plan->lead_assign_methods),
        ];
        self::$memo[$company->id] = ['t' => time(), 'd' => $d];
        return $d;
    }

    public static function has(?Company $company, string $module): bool
    {
        $e = self::forCompany($company);
        return $e['all'] || in_array($module, $e['modules'], true);
    }

    private static function open(): array
    {
        return ['all' => true, 'modules' => Modules::keys(), 'from_plan' => Modules::keys(), 'from_addons' => [], 'from_complimentary' => [], 'complimentary' => [], 'locked' => [], 'assign_methods' => array_keys(Modules::ASSIGN_METHODS)];
    }

    /**
     * Free features the company's plan offers for a limited time. The period starts the first time the company is on a plan that offers
     * the feature and never restarts (so renewing or switching plans cannot farm it); once it ends the feature locks until bought as an add-on.
     * @return array<string,string> module => expiry (ISO) for the ones still running
     */
    private static function complimentary(Company $company, \App\Models\Plan $plan): array
    {
        $offer = $plan->complimentaryMap();
        if (!$offer || !Schema::hasTable('company_complimentary')) { return []; }
        $rows = DB::table('company_complimentary')->where('company_id', $company->id)->get()->keyBy('module');
        $out = [];
        foreach ($offer as $module => $months) {
            $row = $rows->get($module);
            if (!$row) {
                $start = now(); $end = $start->copy()->addMonths($months);
                DB::table('company_complimentary')->insert(['company_id' => $company->id, 'module' => $module, 'plan_id' => $plan->id, 'months' => $months,
                    'starts_at' => $start, 'expires_at' => $end, 'created_at' => $start, 'updated_at' => $start]);
                $out[$module] = $end->toIso8601String();
            } elseif (\Illuminate\Support\Carbon::parse($row->expires_at)->isFuture()) {
                $out[$module] = \Illuminate\Support\Carbon::parse($row->expires_at)->toIso8601String();
            }
        }
        return $out;
    }

    /** Module keys the super admin has switched off for this company. */
    public static function disabled(int $companyId): array
    {
        if (!Schema::hasTable('company_module_overrides')) { return []; }
        return DB::table('company_module_overrides')->where('company_id', $companyId)->pluck('module')->all();
    }

    /** Module keys switched on by active, unexpired "module" add-ons (a retired add-on still counts until it expires). */
    private static function addonModules(int $companyId): array
    {
        if (!Schema::hasTable('company_addons')) { return []; }
        $rows = DB::table('company_addons as ca')->join('addons as a', 'a.id', '=', 'ca.addon_id')
            ->where('ca.company_id', $companyId)->where('ca.status', 'active')->where('a.type', 'module')
            ->where(fn ($q) => $q->whereNull('ca.expires_at')->orWhere('ca.expires_at', '>', now()))
            ->pluck('a.config');
        $out = [];
        foreach ($rows as $json) {
            $c = is_string($json) ? json_decode($json, true) : (array) $json;
            if (!empty($c['module']) && isset(Modules::REGISTRY[$c['module']])) { $out[] = $c['module']; }
        }
        return array_values(array_unique($out));
    }
}
