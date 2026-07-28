<?php
namespace App\Modules\Report\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    // ── Superadmin overall report ─────────────────────────────────────────────
    public function platformReport(Request $request): JsonResponse
    {
        $from = $request->from ?? now()->subDays(30)->toDateString();
        $to   = $request->to   ?? now()->toDateString();

        return response()->json([
            'period' => ['from' => $from, 'to' => $to],
            'companies' => [
                'total'        => DB::table('companies')->whereNull('deleted_at')->count(),
                'new_period'   => DB::table('companies')->whereNull('deleted_at')->whereBetween('created_at', [$from, $to])->count(),
                'active'       => DB::table('companies')->where('status','active')->count(),
                'trial'        => DB::table('companies')->where('status','trial')->count(),
                'suspended'    => DB::table('companies')->where('status','suspended')->count(),
                'expired'      => DB::table('companies')->where('status','expired')->count(),
            ],
            'revenue' => [
                'total'        => DB::table('payment_orders')->where('status','paid')->sum('amount'),
                'period'       => DB::table('payment_orders')->where('status','paid')->whereBetween('created_at',[$from,$to])->sum('amount'),
                'transactions' => DB::table('payment_orders')->where('status','paid')->whereBetween('created_at',[$from,$to])->count(),
                'monthly'      => DB::table('payment_orders')->where('status','paid')
                    ->where('created_at','>=',now()->subMonths(6))
                    ->select(DB::raw("DATE_FORMAT(created_at,'%Y-%m') as month"), DB::raw('sum(amount) as total'))
                    ->groupBy('month')->orderBy('month')->get(),
            ],
            'messages' => [
                'total'    => DB::table('message_logs')->count(),
                'period'   => DB::table('message_logs')->whereBetween('created_at',[$from,$to])->count(),
                'outbound' => DB::table('message_logs')->where('direction','outbound')->whereBetween('created_at',[$from,$to])->count(),
                'inbound'  => DB::table('message_logs')->where('direction','inbound')->whereBetween('created_at',[$from,$to])->count(),
            ],
            'plans' => DB::table('company_plans as cp')
                ->join('plans as p','cp.plan_id','=','p.id')
                ->select('p.name', DB::raw('count(*) as subscribers'), DB::raw('sum(cp.amount_paid) as revenue'))
                ->where('cp.status','active')
                ->groupBy('p.name')->get(),
        ]);
    }

    // ── Per-company report ────────────────────────────────────────────────────
    public function companyReport(Request $request, int $companyId): JsonResponse
    {
        $from = $request->from ?? now()->subDays(30)->toDateString();
        $to   = $request->to   ?? now()->toDateString();

        $company = \App\Models\Company::with(['plan','wallet'])->findOrFail($companyId);

        return response()->json([
            'company' => ['id' => $company->id, 'name' => $company->name, 'status' => $company->status, 'plan' => $company->plan?->name],
            'period'  => ['from' => $from, 'to' => $to],
            'messages' => [
                'total'    => DB::table('message_logs')->where('company_id',$companyId)->count(),
                'period'   => DB::table('message_logs')->where('company_id',$companyId)->whereBetween('created_at',[$from,$to])->count(),
                'outbound' => DB::table('message_logs')->where('company_id',$companyId)->where('direction','outbound')->whereBetween('created_at',[$from,$to])->count(),
                'inbound'  => DB::table('message_logs')->where('company_id',$companyId)->where('direction','inbound')->whereBetween('created_at',[$from,$to])->count(),
            ],
            'leads' => [
                'total'           => DB::table('leads')->where('company_id',$companyId)->whereNull('deleted_at')->count(),
                'new_period'      => DB::table('leads')->where('company_id',$companyId)->whereBetween('created_at',[$from,$to])->count(),
                'by_stage'        => DB::table('leads')->where('company_id',$companyId)->whereNull('deleted_at')->select('stage',DB::raw('count(*) as total'))->groupBy('stage')->pluck('total','stage'),
                'conversion_rate' => $this->conversionRate($companyId),
            ],
            'campaigns' => [
                'total'      => DB::table('campaigns')->where('company_id',$companyId)->whereNull('deleted_at')->count(),
                'period'     => DB::table('campaigns')->where('company_id',$companyId)->whereBetween('created_at',[$from,$to])->count(),
                'by_status'  => DB::table('campaigns')->where('company_id',$companyId)->whereNull('deleted_at')->select('status',DB::raw('count(*) as total'))->groupBy('status')->pluck('total','status'),
            ],
            'wallet' => [
                'balance'         => $company->wallet?->balance ?? 0,
                'total_purchased' => $company->wallet?->total_purchased ?? 0,
                'total_used'      => $company->wallet?->total_used ?? 0,
                'purchases_period'=> DB::table('wallet_transactions')->where('company_id',$companyId)->where('type','credit')->whereBetween('created_at',[$from,$to])->sum('amount'),
            ],
            'purchases' => DB::table('company_plans as cp')->join('plans as p','cp.plan_id','=','p.id')
                ->where('cp.company_id',$companyId)->select('p.name','cp.duration_type','cp.amount_paid','cp.status','cp.starts_at','cp.expires_at')->orderByDesc('cp.created_at')->get(),
        ]);
    }

    // ── Purchase report (all companies) ──────────────────────────────────────
    public function purchaseReport(Request $request): JsonResponse
    {
        $from = $request->from ?? now()->subDays(30)->toDateString();
        $to   = $request->to   ?? now()->toDateString();

        $planPurchases = DB::table('company_plans as cp')
            ->join('companies as c','cp.company_id','=','c.id')
            ->join('plans as p','cp.plan_id','=','p.id')
            ->whereBetween('cp.created_at',[$from,$to])
            ->select('c.name as company','p.name as plan','cp.duration_type','cp.amount_paid','cp.status','cp.starts_at','cp.expires_at','cp.created_at')
            ->orderByDesc('cp.created_at')->paginate(30);

        $walletTopups = DB::table('wallet_transactions as wt')
            ->join('companies as c','wt.company_id','=','c.id')
            ->where('wt.type','credit')
            ->where('wt.reference_type','recharge')
            ->whereBetween('wt.created_at',[$from,$to])
            ->select('c.name as company','wt.amount','wt.description','wt.created_at')
            ->orderByDesc('wt.created_at')->limit(100)->get();

        return response()->json([
            'period'        => ['from' => $from, 'to' => $to],
            'plan_purchases'=> $planPurchases,
            'wallet_topups' => $walletTopups,
            'summary' => [
                'plan_revenue'   => DB::table('company_plans')->whereBetween('created_at',[$from,$to])->sum('amount_paid'),
                'topup_revenue'  => DB::table('payment_orders')->where('status','paid')->whereBetween('created_at',[$from,$to])->sum('amount'),
                'total_revenue'  => DB::table('payment_orders')->where('status','paid')->whereBetween('created_at',[$from,$to])->sum('amount'),
            ],
        ]);
    }

    private function conversionRate(int $companyId): float
    {
        $total    = DB::table('leads')->where('company_id',$companyId)->whereNull('deleted_at')->count();
        $enrolled = DB::table('leads')->where('company_id',$companyId)->where('stage','enrolled')->whereNull('deleted_at')->count();
        return $total > 0 ? round(($enrolled / $total) * 100, 1) : 0;
    }
}
