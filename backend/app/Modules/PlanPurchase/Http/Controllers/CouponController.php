<?php

namespace App\Modules\PlanPurchase\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Plan;
use App\Support\CouponService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Superadmin coupon CRUD + usage analytics, and the public "does this code work?" check used at sign-up. */
class CouponController extends Controller
{
    private function rules(?int $ignore = null): array
    {
        return [
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/', \Illuminate\Validation\Rule::unique('coupons', 'code')->ignore($ignore)->whereNull('deleted_at')],
            'name' => ['required', 'string', 'max:100'], 'description' => ['nullable', 'string', 'max:500'],
            'discount_type' => ['required', 'in:percent,flat'],
            'discount_value' => ['required', 'numeric', 'min:0.01'],
            'max_discount' => ['nullable', 'numeric', 'min:0'], 'min_amount' => ['nullable', 'numeric', 'min:0'],
            'applies_to' => ['nullable', 'array'], 'applies_to.*' => ['in:plan,addon,topup'],
            'plan_ids' => ['nullable', 'array'], 'plan_ids.*' => ['integer'], 'addon_ids' => ['nullable', 'array'], 'addon_ids.*' => ['integer'],
            'durations' => ['nullable', 'array'], 'durations.*' => ['in:monthly,3month,6month,yearly,12month'],
            'first_purchase_only' => ['nullable', 'boolean'], 'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'max_redemptions' => ['nullable', 'integer', 'min:1'], 'per_company_limit' => ['nullable', 'integer', 'min:1'],
            'starts_at' => ['nullable', 'date'], 'expires_at' => ['nullable', 'date', 'after_or_equal:starts_at'], 'is_active' => ['nullable', 'boolean'],
        ];
    }

    private function clean(array $d): array
    {
        if (isset($d['code'])) { $d['code'] = Coupon::normalize($d['code']); }
        if (($d['discount_type'] ?? null) === 'percent' && ($d['discount_value'] ?? 0) > 100) { abort(422, 'A percentage coupon cannot exceed 100%.'); }
        foreach (['applies_to', 'plan_ids', 'addon_ids', 'durations'] as $k) { if (array_key_exists($k, $d) && empty($d[$k])) { $d[$k] = null; } }
        return $d;
    }

    /** GET superadmin/coupons — every coupon with how often it was used and what it cost. */
    public function index(): JsonResponse
    {
        $stats = CouponRedemption::select('coupon_id', DB::raw('count(*) as uses'), DB::raw('coalesce(sum(discount),0) as discount_given'), DB::raw('coalesce(sum(final_amount),0) as revenue'))
            ->groupBy('coupon_id')->get()->keyBy('coupon_id');
        $coupons = Coupon::withTrashed()->latest()->get()->map(function (Coupon $c) use ($stats) {
            $s = $stats->get($c->id);
            return $c->toArray() + ['uses' => (int) ($s->uses ?? 0), 'discount_given' => (float) ($s->discount_given ?? 0), 'revenue' => (float) ($s->revenue ?? 0)];
        });
        return response()->json(['coupons' => $coupons, 'plans' => Plan::select('id', 'name')->orderBy('price')->get(), 'addons' => \App\Models\Addon::select('id', 'name')->orderBy('name')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $d = $this->clean($request->validate($this->rules()));
        return response()->json(['coupon' => Coupon::create($d + ['created_by' => auth()->id()])], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $c = Coupon::withTrashed()->findOrFail($id);
        $c->update($this->clean($request->validate($this->rules($id))));
        return response()->json(['coupon' => $c->fresh()]);
    }

    public function destroy(int $id): JsonResponse
    {
        Coupon::findOrFail($id)->delete();     // redemption history stays
        return response()->json(['message' => 'Coupon deleted. Its usage history is kept.']);
    }

    public function restore(int $id): JsonResponse
    {
        $c = Coupon::onlyTrashed()->findOrFail($id); $c->restore();
        return response()->json(['coupon' => $c]);
    }

    /** GET superadmin/coupons/{id}/usage — who used it, when, and what it discounted. */
    public function usage(int $id): JsonResponse
    {
        $c = Coupon::withTrashed()->findOrFail($id);
        $rows = CouponRedemption::with('company:id,name,email')->where('coupon_id', $id)->latest()->limit(500)->get();
        $monthly = CouponRedemption::where('coupon_id', $id)
            ->select(DB::raw("DATE_FORMAT(created_at,'%Y-%m') as month"), DB::raw('count(*) as uses'), DB::raw('sum(discount) as discount'), DB::raw('sum(final_amount) as revenue'))
            ->groupBy('month')->orderBy('month')->get();
        return response()->json([
            'coupon' => $c,
            'totals' => ['uses' => $rows->count(), 'discount_given' => (float) $rows->sum('discount'), 'revenue' => (float) $rows->sum('final_amount'), 'unique_companies' => $rows->pluck('company_id')->filter()->unique()->count(),
                         'remaining' => $c->max_redemptions ? max(0, $c->max_redemptions - $rows->count()) : null],
            'monthly' => $monthly, 'redemptions' => $rows,
        ]);
    }

    /** GET superadmin/coupons/analytics — leaderboard + overall effect of all coupons. */
    public function analytics(): JsonResponse
    {
        $top = CouponRedemption::select('coupon_id', DB::raw('count(*) as uses'), DB::raw('sum(discount) as discount_given'), DB::raw('sum(final_amount) as revenue'))
            ->groupBy('coupon_id')->orderByDesc('uses')->limit(10)->with('coupon:id,code,name')->get();
        return response()->json([
            'totals' => ['redemptions' => CouponRedemption::count(), 'discount_given' => (float) CouponRedemption::sum('discount'), 'revenue' => (float) CouponRedemption::sum('final_amount'),
                         'active_coupons' => Coupon::where('is_active', true)->count()],
            'top' => $top,
        ]);
    }

    /** POST public/coupons/validate — sign-up page: apply a code to the chosen plan before paying (nothing is recorded). */
    public function publicCheck(Request $request, \App\Modules\PlanPurchase\Services\PlanPurchaseService $svc): JsonResponse
    {
        $d = $request->validate(['plan_id' => ['required', 'integer', 'exists:plans,id'], 'duration_type' => ['required', 'in:monthly,3month,6month,yearly,12month'], 'coupon_code' => ['required', 'string', 'max:40'], 'email' => ['nullable', 'email']]);
        $q = $svc->quotePlan(null, $d['plan_id'], $d['duration_type'], $d['coupon_code'], $d['email'] ?? null);
        return response()->json(['subtotal' => $q['subtotal'], 'discount' => $q['discount'], 'final' => $q['final'], 'coupon' => $q['coupon']?->only(['code', 'name', 'discount_type', 'discount_value'])]);
    }
}
