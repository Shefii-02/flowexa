<?php

namespace App\Modules\PlanPurchase\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Superadmin: which companies' plans / trials / add-ons are about to lapse (or just did), and who has already been told. */
class PlanMonitorController extends Controller
{
    /** GET superadmin/expiries?days=7 — lapsing in the next N days, plus anything that lapsed in the last 30. */
    public function expiries(Request $request): JsonResponse
    {
        $days  = min(max((int) $request->query('days', 7), 1), 90);
        $from  = now()->subDays(30);
        $until = now()->addDays($days);

        $lapsing = fn ($q) => $q->whereBetween('c.plan_expires_at', [$from, $until])->orWhereBetween('c.trial_ends_at', [$from, $until]);
        $companyIds = collect(
            DB::table('companies as c')->whereIn('c.status', ['trial', 'active', 'expired'])->where($lapsing)->pluck('c.id')
        )->merge(
            DB::table('company_addons as ca')->where('ca.status', 'active')->whereNotNull('ca.expires_at')
                ->whereBetween('ca.expires_at', [$from, $until])->pluck('ca.company_id')
        )->unique()->values();

        $companies = Company::with('plan:id,name')->whereIn('id', $companyIds)->get();
        $owners = DB::table('users as u')->join('roles as r', 'r.id', '=', 'u.role_id')->where('r.name', 'owner')
            ->whereIn('u.company_id', $companyIds)->get(['u.company_id', 'u.name', 'u.email', 'u.phone'])->groupBy('company_id');
        $addons = DB::table('company_addons as ca')->join('addons as a', 'a.id', '=', 'ca.addon_id')
            ->whereIn('ca.company_id', $companyIds)->where('ca.status', 'active')->whereNotNull('ca.expires_at')
            ->whereBetween('ca.expires_at', [$from, $until])
            ->get(['ca.id', 'ca.company_id', 'ca.expires_at', 'ca.billing_cycle', 'a.name', 'a.config'])->groupBy('company_id');
        $follow = DB::table('plan_expiry_followups as f')->leftJoin('users as u', 'u.id', '=', 'f.contacted_by')
            ->whereIn('f.company_id', $companyIds)->orderByDesc('f.contacted_at')
            ->get(['f.company_id', 'f.kind', 'f.reference_id', 'f.note', 'f.contacted_at', 'u.name as by'])->groupBy('company_id');

        $d = fn ($t) => $t ? (int) ceil(now()->floatDiffInDays($t, false)) : null;
        $rows = $companies->map(function (Company $c) use ($owners, $addons, $follow, $d) {
            $onTrial = $c->status === 'trial';
            $ends = $onTrial ? $c->trial_ends_at : $c->plan_expires_at;
            $owner = optional($owners->get($c->id))->first();
            return [
                'company_id' => $c->id, 'name' => $c->name, 'status' => $c->status,
                'email' => $c->email, 'phone' => $c->phone,
                'owner' => $owner ? ['name' => $owner->name, 'email' => $owner->email, 'phone' => $owner->phone] : null,
                'plan' => ['kind' => $onTrial ? 'trial' : 'plan', 'name' => $c->plan?->name, 'ends_at' => $ends, 'days_left' => $d($ends)],
                'addons' => ($addons->get($c->id) ?? collect())->map(function ($a) use ($d) {
                    $cfg = is_string($a->config) ? json_decode($a->config, true) : (array) $a->config;
                    return ['id' => $a->id, 'name' => $a->name, 'resource' => $cfg['resource'] ?? null, 'amount' => $cfg['amount'] ?? null,
                            'cycle' => $a->billing_cycle, 'expires_at' => $a->expires_at, 'days_left' => $d($a->expires_at)];
                })->values(),
                'last_followup' => optional($follow->get($c->id))->first(),
            ];
        })->sortBy(fn ($r) => min(array_filter([
            $r['plan']['days_left'] !== null && $r['plan']['ends_at'] && abs($r['plan']['days_left']) <= 90 ? $r['plan']['days_left'] : null,
            ...$r['addons']->pluck('days_left')->all(),
        ], fn ($v) => $v !== null) ?: [999]))->values();

        return response()->json(['days' => $days, 'companies' => $rows]);
    }

    /** POST superadmin/expiries/{company}/contacted — note that staff told this company (plan, trial or one add-on). */
    public function contacted(Request $request, int $company): JsonResponse
    {
        $d = $request->validate([
            'kind'         => ['required', 'in:plan,trial,addon'],
            'reference_id' => ['nullable', 'integer'],
            'note'         => ['nullable', 'string', 'max:1000'],
        ]);
        Company::findOrFail($company);
        $id = DB::table('plan_expiry_followups')->insertGetId([
            'company_id' => $company, 'kind' => $d['kind'], 'reference_id' => $d['reference_id'] ?? null,
            'contacted_by' => auth()->id(), 'note' => $d['note'] ?? null, 'contacted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        return response()->json(['message' => 'Noted.', 'id' => $id], 201);
    }
}
