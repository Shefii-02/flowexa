<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ListingSale;
use App\Modules\Hr\Models\HrStaffProfile;
use App\Modules\Hr\Support\AttendanceService;
use App\Modules\Hr\Support\SalesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class SalesController extends Controller
{
    public function __construct(
        private readonly SalesService $sales,
        private readonly AttendanceService $attendanceSvc,
    ) {}

    private function companyId(): int
    {
        return (int) auth()->user()->company_id;
    }

    private function guard(): void
    {
        $u = auth()->user();
        abort_unless(
            $u->isOwner() || $u->isSuperAdmin() || $u->hasAnyPermission(['hr.manage', 'leads.manage']),
            403,
            'Not allowed.'
        );
    }

    // GET /hr/sales — recorded sales, filterable.
    public function index(Request $request): JsonResponse
    {
        $this->guard();

        $q = ListingSale::where('company_id', $this->companyId())
            ->with(['staff:id,name', 'listing:id,title,type', 'contact:id,name,phone', 'incentive:id,amount,status'])
            ->when($request->filled('staff_id'), fn ($x) => $x->where('staff_id', $request->integer('staff_id')))
            ->when($request->filled('listing_id'), fn ($x) => $x->where('listing_id', $request->integer('listing_id')))
            ->when($request->filled('from'), fn ($x) => $x->whereDate('sold_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($x) => $x->whereDate('sold_at', '<=', $request->date('to')))
            ->when($request->filled('month'), function ($x) use ($request) {
                $m = (string) $request->string('month');
                $x->whereYear('sold_at', substr($m, 0, 4))->whereMonth('sold_at', substr($m, 5, 2));
            })
            ->orderByDesc('sold_at')
            ->orderByDesc('id');

        return response()->json($q->paginate(100));
    }

    // POST /hr/sales — manually record a sale.
    public function store(Request $request): JsonResponse
    {
        $this->guard();
        $companyId = $this->companyId();

        $data = $request->validate([
            'listing_id'  => ['nullable', 'integer', Rule::exists('listings', 'id')->where('company_id', $companyId)],
            'lead_id'     => ['nullable', 'integer', Rule::exists('leads', 'id')->where('company_id', $companyId)],
            'contact_id'  => ['nullable', 'integer', Rule::exists('contacts', 'id')->where('company_id', $companyId)],
            'staff_id'    => ['required', 'integer', Rule::exists('users', 'id')->where('company_id', $companyId)],
            'item_label'  => ['nullable', 'string', 'max:200'],
            'amount'      => ['required', 'numeric', 'min:0'],
            'currency'    => ['nullable', 'string', 'max:8'],
            'sold_at'     => ['required', 'date'],
            'note'        => ['nullable', 'string', 'max:500'],
        ]);

        if (empty($data['listing_id']) && empty($data['item_label'])) {
            return response()->json(['message' => 'Pick a catalog item or type an item name.'], 422);
        }

        $sale = $this->sales->record($companyId, $data);

        return response()->json([
            'message' => 'Sale recorded.',
            'data'    => $sale->load(['staff:id,name', 'listing:id,title', 'incentive:id,amount,status']),
        ], 201);
    }

    // DELETE /hr/sales/{id} — void a sale (removes its unpaid incentive).
    public function destroy(int $id): JsonResponse
    {
        $this->guard();

        $sale = ListingSale::where('company_id', $this->companyId())->findOrFail($id);
        $this->sales->void($sale);

        return response()->json(['message' => 'Sale voided.']);
    }

    // GET /hr/sales/summary?month=YYYY-MM — per-staff sold total vs monthly target.
    public function summary(Request $request): JsonResponse
    {
        $this->guard();
        $companyId = $this->companyId();
        $month = (string) ($request->string('month') ?: now()->format('Y-m'));

        $sales = ListingSale::where('company_id', $companyId)
            ->whereYear('sold_at', substr($month, 0, 4))
            ->whereMonth('sold_at', substr($month, 5, 2))
            ->with('incentive:id,source_id,amount')
            ->get();

        $targets = HrStaffProfile::where('company_id', $companyId)
            ->with('user:id,name')
            ->get()
            ->keyBy('user_id');

        $byStaff = $sales->groupBy('staff_id')->map(function ($rows, $staffId) use ($targets) {
            $profile = $targets->get($staffId);
            $sold = (float) $rows->sum('amount');
            $target = (float) ($profile?->monthly_target ?? 0);

            return [
                'staff_id'   => (int) $staffId,
                'staff_name' => $profile?->user?->name ?? "Staff #{$staffId}",
                'sales_count'=> $rows->count(),
                'sold_total' => round($sold, 2),
                'target'     => $target,
                'progress'   => $target > 0 ? round(min($sold / $target * 100, 999), 1) : null,
                'incentive_total' => round((float) $rows->sum(fn ($s) => (float) ($s->incentive->amount ?? 0)), 2),
            ];
        })->values();

        return response()->json(['month' => $month, 'data' => $byStaff]);
    }

    /**
     * GET /hr/sales/me?month=YYYY-MM — the mobile Target screen: own progress, a weekly
     * breakdown (the month split into even 7-day chunks, target divided evenly across them),
     * days left to hit it, and where the caller stands against active teammates who also have
     * a target set. Self-scoped, no permission needed.
     */
    public function me(Request $request): JsonResponse
    {
        $companyId = $this->companyId();
        $userId = (int) auth()->id();
        $month = (string) ($request->string('month') ?: now()->format('Y-m'));
        $monthStart = Carbon::parse($month . '-01')->startOfMonth();
        $monthEnd = $monthStart->copy()->endOfMonth();
        $isCurrentMonth = $monthStart->isSameMonth(now());
        $today = today();

        $profile = HrStaffProfile::forUser($companyId, $userId);
        $target = (float) ($profile->monthly_target ?? 0);

        $sales = ListingSale::where('company_id', $companyId)->where('staff_id', $userId)
            ->whereBetween('sold_at', [$monthStart, $monthEnd])->get(['amount', 'sold_at']);
        $achieved = (float) $sales->sum('amount');

        $numWeeks = (int) ceil($monthStart->daysInMonth / 7);
        $weeklyTarget = $numWeeks > 0 ? $target / $numWeeks : 0;
        $weekly = [];
        for ($w = 0; $w < $numWeeks; $w++) {
            $weekStart = $monthStart->copy()->addDays($w * 7);
            $weekEnd = $weekStart->copy()->addDays(6);
            if ($weekEnd->gt($monthEnd)) {
                $weekEnd = $monthEnd->copy();
            }
            $started = !$isCurrentMonth || $today->gte($weekStart);
            $sold = (float) $sales->filter(fn ($s) => $s->sold_at->between($weekStart, $weekEnd))->sum('amount');

            $weekly[] = [
                'label'   => 'Wk ' . ($w + 1),
                'sold'    => round($sold, 2),
                'percent' => $started && $weeklyTarget > 0 ? round(min($sold / $weeklyTarget * 100, 999), 1) : null,
            ];
        }

        $daysLeft = 0;
        if ($isCurrentMonth) {
            for ($d = $today->copy()->addDay(); $d->lte($monthEnd); $d->addDay()) {
                if ($this->attendanceSvc->isWorkingDay($profile, $d)) {
                    $daysLeft++;
                }
            }
        }

        return response()->json([
            'month'       => $month,
            'target'      => $target,
            'achieved'    => round($achieved, 2),
            'remaining'   => max(0, round($target - $achieved, 2)),
            'progress'    => $target > 0 ? round(min($achieved / $target * 100, 999), 1) : null,
            'days_left'   => $daysLeft,
            'weekly'      => $weekly,
            'leaderboard' => $this->leaderboard($companyId, $monthStart, $monthEnd, $userId),
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function leaderboard(int $companyId, Carbon $monthStart, Carbon $monthEnd, int $userId): array
    {
        $sold = ListingSale::where('company_id', $companyId)
            ->whereBetween('sold_at', [$monthStart, $monthEnd])
            ->selectRaw('staff_id, SUM(amount) as total')->groupBy('staff_id')->pluck('total', 'staff_id');

        $rows = HrStaffProfile::where('company_id', $companyId)->where('is_active', true)
            ->whereNotNull('monthly_target')->where('monthly_target', '>', 0)
            ->with('user:id,name')
            ->get()
            ->map(function (HrStaffProfile $p) use ($sold) {
                $achieved = (float) ($sold[$p->user_id] ?? 0);
                $target = (float) $p->monthly_target;

                return [
                    'user_id' => (int) $p->user_id,
                    'name'    => $p->user?->name ?? "Staff #{$p->user_id}",
                    'percent' => $target > 0 ? round(min($achieved / $target * 100, 999), 1) : 0,
                ];
            })
            ->sortByDesc('percent')
            ->values();

        return $rows->take(20)->values()->map(function (array $r, int $i) use ($userId) {
            $r['rank'] = $i + 1;
            $r['is_me'] = $r['user_id'] === $userId;

            return $r;
        })->all();
    }
}
