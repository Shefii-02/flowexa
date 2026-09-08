<?php

namespace App\Modules\Hr\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hr\Models\HrPayrollItem;
use App\Modules\Hr\Models\HrPayrollRun;
use App\Modules\Hr\Support\PayrollService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PayrollController extends Controller
{
    public function __construct(private readonly PayrollService $payroll) {}

    private function companyId(): int
    {
        return (int) auth()->user()->company_id;
    }

    private function guard(): void
    {
        $u = auth()->user();
        abort_unless(
            $u->isOwner() || $u->isSuperAdmin() || $u->hasAnyPermission(['hr.manage', 'hr.leave.approve']),
            403, 'No payroll permission.',
        );
    }

    public function runs(): JsonResponse
    {
        $this->guard();

        return response()->json(['data' => HrPayrollRun::where('company_id', $this->companyId())
            ->withCount('items')->orderByDesc('period')->get()]);
    }

    public function show(int $id): JsonResponse
    {
        $this->guard();

        $run = HrPayrollRun::where('company_id', $this->companyId())
            ->with('items.user:id,name,department')->findOrFail($id);

        return response()->json(['data' => $run]);
    }

    /** POST /hr/payroll/runs { period: YYYY-MM } — generate or re-generate a draft run. */
    public function generate(Request $request): JsonResponse
    {
        $this->guard();
        $data = $request->validate(['period' => 'required|date_format:Y-m']);

        $run = $this->payroll->generate($this->companyId(), $data['period'], (int) auth()->id());

        return response()->json(['data' => $run], 201);
    }

    /** PATCH /hr/payroll/items/{id} — custom edit one staff member's pay line. */
    public function updateItem(Request $request, int $id): JsonResponse
    {
        $this->guard();

        $item = HrPayrollItem::whereHas('run', fn ($q) => $q->where('company_id', $this->companyId()))->findOrFail($id);
        if ($item->run->isReleased()) {
            throw ValidationException::withMessages(['payroll' => 'This run is released and cannot be edited.']);
        }

        $data = $request->validate([
            'base_pay'      => 'nullable|numeric|min:0',
            'overtime_pay'  => 'nullable|numeric|min:0',
            'incentive_pay' => 'nullable|numeric|min:0',
            'allowances'    => 'nullable|numeric|min:0',
            'deductions'    => 'nullable|numeric|min:0',
            'note'          => 'nullable|string|max:500',
            'adjustments'              => 'nullable|array',
            'adjustments.*.label'      => 'required_with:adjustments|string|max:80',
            'adjustments.*.amount'     => 'required_with:adjustments|numeric',
        ]);

        $item->fill($data);
        $item->recompute();
        $item->save();

        $item->run->update(['totals' => $this->runTotals($item->run->fresh())]);

        return response()->json(['data' => $item->fresh()->load('user:id,name')]);
    }

    /** POST /hr/payroll/runs/{id}/release — lock the run + mark incentives paid. */
    public function release(int $id): JsonResponse
    {
        $this->guard();

        $run = HrPayrollRun::where('company_id', $this->companyId())->findOrFail($id);
        if ($run->isReleased()) {
            throw ValidationException::withMessages(['payroll' => 'Already released.']);
        }

        return response()->json(['data' => $this->payroll->release($run, (int) auth()->id())]);
    }

    /** GET /hr/payroll/runs/{id}/report — flat rows for export / a payslip view. */
    public function report(int $id): JsonResponse
    {
        $this->guard();

        $run = HrPayrollRun::where('company_id', $this->companyId())
            ->with('items.user:id,name,email,department')->findOrFail($id);

        $rows = $run->items->map(fn (HrPayrollItem $i) => [
            'staff'         => $i->user?->name,
            'department'    => $i->user?->department,
            'present_days'  => $i->present_days,
            'paid_leave'    => $i->paid_leave_days,
            'unpaid_leave'  => $i->unpaid_leave_days,
            'absent_days'   => $i->absent_days,
            'late_days'     => $i->late_days,
            'worked_hours'  => $i->worked_hours,
            'overtime_hours' => $i->overtime_hours,
            'base_pay'      => $i->base_pay,
            'overtime_pay'  => $i->overtime_pay,
            'incentive_pay' => $i->incentive_pay,
            'allowances'    => $i->allowances,
            'deductions'    => $i->deductions,
            'gross_pay'     => $i->gross_pay,
            'net_pay'       => $i->net_pay,
            'note'          => $i->note,
        ]);

        return response()->json([
            'period'   => $run->period,
            'status'   => $run->status,
            'released_at' => $run->released_at,
            'totals'   => $run->totals,
            'rows'     => $rows,
        ]);
    }

    /** @return array<string, float> */
    private function runTotals(HrPayrollRun $run): array
    {
        $items = $run->items()->get();
        return [
            'staff'         => $items->count(),
            'base_pay'      => round($items->sum('base_pay'), 2),
            'overtime_pay'  => round($items->sum('overtime_pay'), 2),
            'incentive_pay' => round($items->sum('incentive_pay'), 2),
            'deductions'    => round($items->sum('deductions'), 2),
            'net_pay'       => round($items->sum('net_pay'), 2),
        ];
    }
}
