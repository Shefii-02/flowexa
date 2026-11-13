<?php

namespace App\Modules\PlanPurchase\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\CompanyAddon;
use App\Models\CompanyPlan;
use App\Models\PaymentOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Superadmin: what was paid, through which gateway, for what — and the monthly revenue picture. */
class RevenueController extends Controller
{
    /** GET superadmin/revenue?months=12 */
    public function summary(Request $request): JsonResponse
    {
        $months = min(36, max(1, (int) $request->query('months', 12)));
        $from = now()->startOfMonth()->subMonths($months - 1);
        $paid = PaymentOrder::where('status', 'paid')->where(DB::raw('coalesce(paid_at, created_at)'), '>=', $from);
        $date = DB::raw("DATE_FORMAT(coalesce(paid_at, created_at),'%Y-%m')");

        $monthly = (clone $paid)->select(DB::raw("DATE_FORMAT(coalesce(paid_at, created_at),'%Y-%m') as month"), 'purpose', DB::raw('count(*) as orders'), DB::raw('sum(amount) as revenue'), DB::raw('sum(discount) as discount'))
            ->groupBy('month', 'purpose')->orderBy('month')->get();
        $byGateway = (clone $paid)->select('gateway', DB::raw('count(*) as orders'), DB::raw('sum(amount) as revenue'))->groupBy('gateway')->orderByDesc('revenue')->get();
        $byMethod  = (clone $paid)->whereNotNull('method')->select('method', DB::raw('count(*) as orders'), DB::raw('sum(amount) as revenue'))->groupBy('method')->orderByDesc('revenue')->get();

        $series = [];
        for ($i = 0; $i < $months; $i++) { $series[$from->copy()->addMonths($i)->format('Y-m')] = ['month' => $from->copy()->addMonths($i)->format('Y-m'), 'plan' => 0.0, 'addon' => 0.0, 'topup' => 0.0, 'total' => 0.0, 'orders' => 0, 'discount' => 0.0]; }
        foreach ($monthly as $r) {
            if (!isset($series[$r->month])) { continue; }
            $bucket = in_array($r->purpose, ['addon', 'cart'], true) ? 'addon' : ($r->purpose === 'plan' ? 'plan' : 'topup');
            $series[$r->month][$bucket] += (float) $r->revenue; $series[$r->month]['total'] += (float) $r->revenue;
            $series[$r->month]['orders'] += (int) $r->orders; $series[$r->month]['discount'] += (float) $r->discount;
        }

        $thisMonth = $series[now()->format('Y-m')] ?? ['total' => 0];
        $lastKey = now()->subMonth()->format('Y-m');
        $prev = $series[$lastKey]['total'] ?? 0;
        return response()->json([
            'months' => array_values($series),
            'by_gateway' => $byGateway, 'by_method' => $byMethod,
            'totals' => [
                'revenue' => round(array_sum(array_column($series, 'total')), 2), 'this_month' => round((float) $thisMonth['total'], 2), 'last_month' => round((float) $prev, 2),
                'discounts' => round(array_sum(array_column($series, 'discount')), 2), 'orders' => array_sum(array_column($series, 'orders')),
                'active_plans' => CompanyPlan::where('status', 'active')->count(), 'active_addons' => CompanyAddon::where('status', 'active')->count(),
            ],
        ]);
    }

    /** GET superadmin/payments?purpose=&gateway=&status=&month=YYYY-MM&q= */
    public function payments(Request $request): JsonResponse
    {
        $q = PaymentOrder::with('company:id,name,email')->latest();
        foreach (['purpose', 'gateway', 'status'] as $f) { if ($request->filled($f)) { $q->where($f, $request->query($f)); } }
        if ($request->filled('month')) { $q->where(DB::raw("DATE_FORMAT(coalesce(paid_at, created_at),'%Y-%m')"), $request->query('month')); }
        if ($request->filled('q')) {
            $t = '%' . $request->query('q') . '%';
            $q->where(fn ($w) => $w->where('razorpay_order_id', 'like', $t)->orWhere('razorpay_payment_id', 'like', $t)->orWhere('coupon_code', 'like', $t)->orWhereHas('company', fn ($c) => $c->where('name', 'like', $t)));
        }
        $page = $q->paginate(min(100, (int) $request->query('per_page', 25)));
        return response()->json($page);
    }

    /** POST superadmin/payments/manual — record money received outside the gateway (bank transfer, cash, UPI to account) so revenue stays complete. */
    public function recordManual(Request $request): JsonResponse
    {
        $d = $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'], 'amount' => ['required', 'numeric', 'min:1'],
            'purpose' => ['required', 'in:plan,addon,topup'], 'method' => ['required', 'string', 'max:30'], 'reference' => ['nullable', 'string', 'max:100'], 'note' => ['nullable', 'string', 'max:300'],
        ]);
        $o = PaymentOrder::create([
            'company_id' => $d['company_id'], 'user_id' => auth()->id(), 'razorpay_order_id' => 'MANUAL_' . uniqid(), 'razorpay_payment_id' => $d['reference'] ?? null,
            'amount' => $d['amount'], 'subtotal' => $d['amount'], 'messages_credit' => 0, 'status' => 'paid', 'purpose' => $d['purpose'], 'gateway' => 'manual', 'method' => $d['method'],
            'meta' => ['note' => $d['note'] ?? null, 'recorded_by' => auth()->id()], 'paid_at' => now(),
        ]);
        return response()->json(['payment' => $o], 201);
    }
}
