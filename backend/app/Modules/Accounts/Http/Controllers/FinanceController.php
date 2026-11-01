<?php

namespace App\Modules\Accounts\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounts\Support\AccountsService;
use App\Modules\Accounts\Models\AccQuotation;
use App\Modules\Accounts\Support\AssetService;
use App\Modules\Accounts\Support\DemoData;
use App\Modules\Accounts\Support\FiscalYearService;
use App\Modules\Accounts\Support\JournalService;
use App\Modules\Accounts\Support\PaymentService;
use App\Modules\Accounts\Support\PayrollService;
use App\Modules\Accounts\Support\PettyCashService;
use App\Modules\Accounts\Support\QuotationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Finance workflow beyond the core voucher/ledger screens: fiscal years, quotations, payments, assets, payroll, petty cash. */
class FinanceController extends Controller
{
    public function __construct(
        private readonly AccountsService $svc,
        private readonly FiscalYearService $fy,
        private readonly QuotationService $quotes,
        private readonly PaymentService $payments,
        private readonly AssetService $assets,
        private readonly JournalService $journals,
        private readonly PayrollService $payroll,
        private readonly PettyCashService $petty,
        private readonly DemoData $demo,
    ) {}

    private function cid(): int { return (int) auth()->user()->company_id; }

    // ─── Fiscal years ─────────────────────────────────────────────────────────
    public function fiscalYears(): JsonResponse
    {
        return response()->json($this->fy->list($this->cid()));
    }

    public function fiscalYearPreview(int $id): JsonResponse
    {
        return response()->json($this->fy->preview($this->cid(), $id));
    }

    public function closeFiscalYear(int $id): JsonResponse
    {
        $y = $this->fy->close($this->cid(), auth()->id(), $id);
        return response()->json(['message' => "{$y->name} closed. Books are locked up to " . $y->end_date->format('d M Y') . '.', 'year' => $y]);
    }

    public function reopenFiscalYear(int $id): JsonResponse
    {
        $y = $this->fy->reopen($this->cid(), $id);
        return response()->json(['message' => "{$y->name} reopened.", 'year' => $y]);
    }

    // ─── Quotations ───────────────────────────────────────────────────────────
    private function quoteRules(): array
    {
        return [
            'date' => ['required', 'date'], 'valid_until' => ['nullable', 'date'], 'party_ledger_id' => ['required', 'integer'],
            'place_of_supply' => ['nullable', 'string', 'size:2'],
            'subject' => ['nullable', 'string', 'max:200'], 'notes' => ['nullable', 'string', 'max:2000'], 'terms' => ['nullable', 'string', 'max:3000'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.item_id' => ['nullable', 'integer'], 'items.*.description' => ['nullable', 'string', 'max:255'], 'items.*.hsn' => ['nullable', 'string', 'max:10'],
            'items.*.qty' => ['nullable', 'numeric', 'min:0'], 'items.*.rate' => ['nullable', 'numeric', 'min:0'], 'items.*.gst_rate' => ['nullable', 'numeric', 'between:0,40'],
        ];
    }

    public function quotations(Request $r): JsonResponse
    {
        $cid = $this->cid();
        $q = AccQuotation::with('party:id,name')->where('company_id', $cid)
            ->when($r->party, fn ($q, $p) => $q->where('party_ledger_id', $p))
            ->when($r->search, fn ($q, $s) => $q->where(fn ($w) => $w->where('number', 'like', "%{$s}%")->orWhere('subject', 'like', "%{$s}%")))
            ->orderByDesc('date')->orderByDesc('id');
        // `expired` is derived from the validity date, so the status filter is applied after loading.
        $all = $q->get();
        if ($r->status) { $all = $all->filter(fn ($x) => $x->effective_status === $r->status)->values(); }
        $per = max(1, min(100, (int) $r->query('per_page', 25))); $page = max(1, (int) $r->query('page', 1));
        return response()->json(['data' => $all->forPage($page, $per)->values(), 'total' => $all->count(), 'per_page' => $per, 'current_page' => $page,
                                 'summary' => $this->quotes->summary($cid)]);
    }

    public function showQuotation(int $id): JsonResponse
    {
        return response()->json(['quotation' => $this->quotes->find($this->cid(), $id), 'company' => $this->svc->settings($this->cid())]);
    }

    public function storeQuotation(Request $r): JsonResponse
    {
        $q = $this->quotes->save($this->cid(), auth()->id(), $r->validate($this->quoteRules()));
        return response()->json(['message' => "{$q->number} created.", 'quotation' => $q], 201);
    }

    public function updateQuotation(Request $r, int $id): JsonResponse
    {
        $q = $this->quotes->save($this->cid(), auth()->id(), $r->validate($this->quoteRules()), AccQuotation::where('company_id', $this->cid())->findOrFail($id));
        return response()->json(['message' => "{$q->number} updated.", 'quotation' => $q]);
    }

    public function quotationStatus(Request $r, int $id): JsonResponse
    {
        $to = $r->validate(['status' => ['required', 'in:draft,sent,accepted,rejected']])['status'];
        $q = $this->quotes->setStatus($this->cid(), $id, $to);
        return response()->json(['message' => "{$q->number} marked {$to}.", 'quotation' => $q]);
    }

    public function convertQuotation(Request $r, int $id): JsonResponse
    {
        $o = $r->validate(['date' => ['nullable', 'date'], 'due_date' => ['nullable', 'date'], 'credit_override' => ['nullable', 'boolean']]);
        [$q, $v] = $this->quotes->convert($this->cid(), auth()->id(), $id, $o);
        return response()->json(['message' => "{$q->number} converted to invoice {$v->number}.", 'quotation' => $q, 'voucher' => $v]);
    }

    public function duplicateQuotation(int $id): JsonResponse
    {
        $q = $this->quotes->duplicate($this->cid(), auth()->id(), $id);
        return response()->json(['message' => "Copied to {$q->number}.", 'quotation' => $q], 201);
    }

    public function destroyQuotation(int $id): JsonResponse
    {
        $this->quotes->delete($this->cid(), $id);
        return response()->json(['message' => 'Quotation deleted.']);
    }

    // ─── Customer credit ──────────────────────────────────────────────────────
    public function credit(): JsonResponse
    {
        $cid = $this->cid();
        $this->svc->seedDefaults($cid);
        $o = $this->svc->creditOverview($cid);
        $late = $this->payments->overdueByParty($cid, 'receivable');
        $o['rows'] = array_map(fn ($r) => $r + ['overdue' => $late[$r['ledger_id']]['overdue'] ?? 0, 'oldest_overdue_days' => $late[$r['ledger_id']]['oldest'] ?? 0], $o['rows']);
        $o['total_overdue'] = round(array_sum(array_column($o['rows'], 'overdue')), 2);
        return response()->json($o);
    }

    // ─── Payments: receivable / payable trackers, ageing, advances, report ────
    private function kind(string $kind): string
    {
        abort_unless(in_array($kind, ['receivable', 'payable'], true), 404);
        return $kind;
    }

    public function documents(Request $r, string $kind): JsonResponse
    {
        $f = $r->validate(['party' => ['nullable', 'integer'], 'status' => ['nullable', 'in:all,open,overdue,partial,paid'], 'search' => ['nullable', 'string', 'max:60'], 'as_of' => ['nullable', 'date']]);
        return response()->json($this->payments->documents($this->cid(), $this->kind($kind), $f));
    }

    public function ageing(Request $r, string $kind): JsonResponse
    {
        return response()->json($this->payments->ageing($this->cid(), $this->kind($kind), $r->query('as_of')));
    }

    public function advances(Request $r, string $kind): JsonResponse
    {
        return response()->json($this->payments->advances($this->cid(), $this->kind($kind), $r->query('party') ? (int) $r->query('party') : null));
    }

    public function applyAdvance(Request $r): JsonResponse
    {
        $d = $r->validate(['advance_id' => ['required', 'integer'], 'invoice_id' => ['required', 'integer'], 'amount' => ['required', 'numeric', 'gt:0'], 'date' => ['nullable', 'date']]);
        $a = $this->payments->applyAdvance($this->cid(), auth()->id(), $d['advance_id'], $d['invoice_id'], (float) $d['amount'], $d['date'] ?? null);
        return response()->json(['message' => 'Advance applied.', 'allocation' => $a], 201);
    }

    public function unapplyAdvance(int $id): JsonResponse
    {
        $this->payments->unapplyAdvance($this->cid(), $id);
        return response()->json(['message' => 'Advance adjustment reversed.']);
    }

    public function paymentReport(Request $r): JsonResponse
    {
        [$fs, $fe] = $this->svc->fyRange($this->cid());
        return response()->json($this->payments->report($this->cid(), $r->query('from') ?: $fs, $r->query('to') ?: $fe));
    }

    // ─── Adjusting entries ────────────────────────────────────────────────────
    public function adjustingEntries(Request $r): JsonResponse
    {
        [$fs, $fe] = $this->svc->fyRange($this->cid());
        return response()->json($this->journals->list($this->cid(), $r->query('from') ?: $fs, $r->query('to') ?: $fe));
    }

    public function storeAdjusting(Request $r): JsonResponse
    {
        $d = $r->validate([
            'kind' => ['required', 'in:' . implode(',', array_keys(JournalService::KINDS))], 'date' => ['required', 'date'], 'amount' => ['required', 'numeric', 'gt:0'],
            'ledger_id' => ['nullable', 'integer'], 'account_ledger_id' => ['nullable', 'integer'], 'auto_reverse' => ['nullable', 'boolean'], 'narration' => ['nullable', 'string', 'max:500'],
        ]);
        $v = $this->journals->adjusting($this->cid(), auth()->id(), $d);
        return response()->json(['message' => "{$v->number} posted" . ($v->reverse_on ? ' — reverses on ' . $v->reverse_on->format('d M Y') : '') . '.', 'voucher' => $v], 201);
    }

    public function cancelReversal(int $id): JsonResponse
    {
        $this->journals->cancelReversal($this->cid(), $id);
        return response()->json(['message' => 'Auto-reversal cancelled.']);
    }

    // ─── Fixed assets ─────────────────────────────────────────────────────────
    public function assets(): JsonResponse
    {
        $this->svc->seedDefaults($this->cid());
        return response()->json($this->assets->register($this->cid()));
    }

    public function storeAsset(Request $r): JsonResponse
    {
        $d = $r->validate([
            'name' => ['required', 'string', 'max:150'], 'tag' => ['nullable', 'string', 'max:30'], 'category' => ['required', 'in:property,equipment,vehicle,intangible'],
            'purchase_date' => ['required', 'date'], 'cost' => ['required', 'numeric', 'gt:0'], 'salvage_value' => ['nullable', 'numeric', 'min:0'],
            'useful_life_months' => ['nullable', 'integer', 'between:1,600'], 'method' => ['nullable', 'in:sl,wdv'], 'wdv_rate' => ['nullable', 'numeric', 'gt:0', 'max:100'],
            'acquisition' => ['nullable', 'in:purchase,existing'], 'paid_from_ledger_id' => ['nullable', 'integer'],
            'opening_accumulated' => ['nullable', 'numeric', 'min:0'], 'depreciated_upto' => ['nullable', 'date'],
            'location' => ['nullable', 'string', 'max:120'], 'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $a = $this->assets->create($this->cid(), auth()->id(), $d);
        return response()->json(['message' => "{$a->name} added to the asset register.", 'asset' => $a], 201);
    }

    public function updateAsset(Request $r, int $id): JsonResponse
    {
        $d = $r->validate([
            'name' => ['sometimes', 'string', 'max:150'], 'tag' => ['nullable', 'string', 'max:30'], 'category' => ['sometimes', 'in:property,equipment,vehicle,intangible'],
            'purchase_date' => ['sometimes', 'date'], 'cost' => ['sometimes', 'numeric', 'gt:0'], 'salvage_value' => ['sometimes', 'numeric', 'min:0'],
            'useful_life_months' => ['sometimes', 'integer', 'between:1,600'], 'method' => ['sometimes', 'in:sl,wdv'], 'wdv_rate' => ['nullable', 'numeric', 'gt:0', 'max:100'],
            'location' => ['nullable', 'string', 'max:120'], 'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        return response()->json(['message' => 'Asset updated.', 'asset' => $this->assets->update($this->cid(), $id, $d)]);
    }

    public function destroyAsset(int $id): JsonResponse
    {
        $this->assets->delete($this->cid(), $id);
        return response()->json(['message' => 'Asset deleted.']);
    }

    public function depreciationPreview(Request $r): JsonResponse
    {
        return response()->json($this->assets->previewRun($this->cid(), $r->query('upto')));
    }

    public function runDepreciation(Request $r): JsonResponse
    {
        $d = $r->validate(['upto' => ['nullable', 'date']]);
        $res = $this->assets->run($this->cid(), auth()->id(), $d['upto'] ?? null);
        return response()->json(['message' => $res['posted'] ? "Depreciation posted for {$res['posted']} month(s): " . number_format($res['total'], 2) . '.' : 'Nothing to depreciate.'] + $res);
    }

    public function undoDepreciation(string $period): JsonResponse
    {
        $this->assets->undoRun($this->cid(), $period);
        return response()->json(['message' => 'Depreciation for that month was removed.']);
    }

    public function disposeAsset(Request $r, int $id): JsonResponse
    {
        $d = $r->validate(['date' => ['required', 'date'], 'proceeds' => ['nullable', 'numeric', 'min:0'], 'account_ledger_id' => ['nullable', 'integer']]);
        $a = $this->assets->dispose($this->cid(), auth()->id(), $id, $d);
        return response()->json(['message' => "{$a->name} disposed — " . ($a->disposal_gain_loss >= 0 ? 'gain ' : 'loss ') . number_format(abs($a->disposal_gain_loss), 2) . '.', 'asset' => $a]);
    }

    // ─── Payroll ──────────────────────────────────────────────────────────────
    private function employeeRules(bool $create): array
    {
        $req = $create ? 'required' : 'sometimes';
        return [
            'name' => [$req, 'string', 'max:150'], 'code' => ['nullable', 'string', 'max:20'], 'designation' => ['nullable', 'string', 'max:100'],
            'joined_on' => [$req, 'date'], 'left_on' => ['nullable', 'date'], 'basic' => [$req, 'numeric', 'gt:0'], 'allowance' => ['nullable', 'numeric', 'min:0'],
            'annual_ticket' => ['nullable', 'numeric', 'min:0'], 'leave_days_per_year' => ['nullable', 'numeric', 'between:0,60'], 'eos_days_per_year' => ['nullable', 'numeric', 'between:0,60'],
            'leave_taken_days' => ['nullable', 'numeric', 'min:0'], 'ticket_used' => ['nullable', 'numeric', 'min:0'], 'user_id' => ['nullable', 'integer'], 'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function employees(): JsonResponse
    {
        return response()->json($this->payroll->employees($this->cid()));
    }

    public function storeEmployee(Request $r): JsonResponse
    {
        $e = $this->payroll->saveEmployee($this->cid(), $r->validate($this->employeeRules(true)));
        return response()->json(['message' => "{$e->name} added.", 'employee' => $e], 201);
    }

    public function updateEmployee(Request $r, int $id): JsonResponse
    {
        $e = $this->payroll->saveEmployee($this->cid(), $r->validate($this->employeeRules(false)), $this->payroll->employee($this->cid(), $id));
        return response()->json(['message' => 'Employee updated.', 'employee' => $e]);
    }

    public function destroyEmployee(int $id): JsonResponse
    {
        $this->payroll->deleteEmployee($this->cid(), $id);
        return response()->json(['message' => 'Employee deleted.']);
    }

    public function settlementQuote(Request $r, int $id): JsonResponse
    {
        return response()->json($this->payroll->quote($this->cid(), $id, $r->query('date')));
    }

    public function settleEmployee(Request $r, int $id): JsonResponse
    {
        $d = $r->validate(['date' => ['nullable', 'date'], 'account_ledger_id' => ['nullable', 'integer']]);
        $e = $this->payroll->settle($this->cid(), auth()->id(), $id, $d);
        return response()->json(['message' => "{$e->name} settled — " . number_format((float) $e->settlement_amount, 2) . '.', 'employee' => $e]);
    }

    public function payrollRuns(): JsonResponse
    {
        return response()->json($this->payroll->runs($this->cid()));
    }

    public function showPayrollRun(int $id): JsonResponse
    {
        return response()->json(['run' => $this->payroll->show($this->cid(), $id), 'company' => $this->svc->settings($this->cid())]);
    }

    public function storePayrollRun(Request $r): JsonResponse
    {
        $run = $this->payroll->createRun($this->cid(), auth()->id(), $r->validate(['period' => ['required', 'string']])['period']);
        return response()->json(['message' => "Payroll for {$run->period} opened.", 'run' => $run], 201);
    }

    public function updatePayrollLine(Request $r, int $id, int $line): JsonResponse
    {
        $d = $r->validate(['commission' => ['nullable', 'numeric', 'min:0'], 'unpaid_days' => ['nullable', 'numeric', 'between:0,31'], 'other_deductions' => ['nullable', 'numeric', 'min:0'], 'notes' => ['nullable', 'string', 'max:255']]);
        return response()->json(['run' => $this->payroll->updateLine($this->cid(), $id, $line, $d)]);
    }

    public function refreshPayrollRun(int $id): JsonResponse
    {
        $run = \App\Modules\Accounts\Models\AccPayrollRun::where('company_id', $this->cid())->where('status', 'draft')->findOrFail($id);
        $this->payroll->fillLines($run);
        return response()->json(['run' => $this->payroll->show($this->cid(), $id)]);
    }

    public function postPayrollRun(int $id): JsonResponse
    {
        $run = $this->payroll->post($this->cid(), auth()->id(), $id);
        return response()->json(['message' => "Payroll {$run->period} posted.", 'run' => $run]);
    }

    public function unpostPayrollRun(int $id): JsonResponse
    {
        return response()->json(['message' => 'Payroll taken back to draft.', 'run' => $this->payroll->unpost($this->cid(), $id)]);
    }

    public function payPayrollRun(Request $r, int $id): JsonResponse
    {
        $d = $r->validate(['account_ledger_id' => ['required', 'integer'], 'date' => ['nullable', 'date']]);
        $run = $this->payroll->pay($this->cid(), auth()->id(), $id, $d['account_ledger_id'], $d['date'] ?? null);
        return response()->json(['message' => "Salaries for {$run->period} paid.", 'run' => $run]);
    }

    public function unpayPayrollRun(int $id): JsonResponse
    {
        return response()->json(['message' => 'Payment reversed.', 'run' => $this->payroll->unpay($this->cid(), $id)]);
    }

    public function destroyPayrollRun(int $id): JsonResponse
    {
        $this->payroll->deleteRun($this->cid(), $id);
        return response()->json(['message' => 'Draft payroll deleted.']);
    }

    // ─── Petty cash ───────────────────────────────────────────────────────────
    public function pettyCash(Request $r): JsonResponse
    {
        [$fs, $fe] = $this->svc->fyRange($this->cid());
        return response()->json($this->petty->summary($this->cid(), $r->query('from') ?: $fs, $r->query('to') ?: $fe));
    }

    public function pettyTopUp(Request $r): JsonResponse
    {
        $d = $r->validate(['date' => ['required', 'date'], 'from_ledger_id' => ['required', 'integer'], 'amount' => ['nullable', 'numeric', 'min:0'], 'narration' => ['nullable', 'string', 'max:255']]);
        $v = $this->petty->topUp($this->cid(), auth()->id(), $d);
        return response()->json(['message' => "{$v->number}: " . number_format($v->total, 2) . ' added to petty cash.', 'voucher' => $v], 201);
    }

    public function pettySpend(Request $r): JsonResponse
    {
        $d = $r->validate(['date' => ['required', 'date'], 'ledger_id' => ['required', 'integer'], 'amount' => ['required', 'numeric', 'gt:0'], 'gst_rate' => ['nullable', 'numeric', 'between:0,40'],
                           'description' => ['nullable', 'string', 'max:255'], 'reference_no' => ['nullable', 'string', 'max:60'], 'narration' => ['nullable', 'string', 'max:500']]);
        $v = $this->petty->spend($this->cid(), auth()->id(), $d);
        return response()->json(['message' => "{$v->number} paid from petty cash.", 'voucher' => $v], 201);
    }

    // ─── Sample data (load for a demo, remove cleanly afterwards) ─────────────
    public function demoStatus(): JsonResponse
    {
        $row = \DB::table('acc_settings')->where('company_id', $this->cid())->first();
        return response()->json(['loaded' => (bool) ($row->demo_seeded_at ?? null), 'loaded_at' => $row->demo_seeded_at ?? null,
            'can_load' => !($row->demo_seeded_at ?? null) && !\App\Modules\Accounts\Models\AccVoucher::where('company_id', $this->cid())->exists()]);
    }

    public function loadDemo(): JsonResponse
    {
        try { $summary = $this->demo->seed($this->cid(), auth()->id()); }
        catch (\RuntimeException $e) { return response()->json(['message' => $e->getMessage()], 422); }
        return response()->json(['message' => 'Sample data loaded. You can remove it again from Settings.', 'summary' => $summary], 201);
    }

    public function removeDemo(): JsonResponse
    {
        try { $removed = $this->demo->remove($this->cid()); }
        catch (\RuntimeException $e) { return response()->json(['message' => $e->getMessage()], 422); }
        return response()->json(['message' => 'Sample data removed.', 'removed' => $removed]);
    }
}
