<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ListingSale;
use App\Modules\Hr\Models\HrStaffProfile;
use App\Modules\Hr\Support\SalesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SalesController extends Controller
{
    public function __construct(private readonly SalesService $sales) {}

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
}
