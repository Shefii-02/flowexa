<?php

namespace App\Modules\Accounts\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounts\Models\AccEntry;
use App\Modules\Accounts\Models\AccFiscalYear;
use App\Modules\Accounts\Models\AccItem;
use App\Modules\Accounts\Models\AccLedger;
use App\Modules\Accounts\Models\AccVoucher;
use App\Modules\Accounts\Support\AccountsService;
use App\Modules\Accounts\Support\ChartOfAccounts;
use App\Modules\Accounts\Support\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AccountsController extends Controller
{
    public function __construct(private readonly AccountsService $svc, private readonly PaymentService $payments) {}

    private function cid(): int { return (int) auth()->user()->company_id; }

    /** from/to default to the current financial year. */
    private function range(Request $r): array
    {
        [$fs, $fe] = $this->svc->fyRange($this->cid());
        return [$r->query('from') ?: $fs, $r->query('to') ?: $fe];
    }

    // ─── Settings ─────────────────────────────────────────────────────────────
    public function settings(): JsonResponse
    {
        $this->svc->seedDefaults($this->cid());
        return response()->json(['settings' => $this->svc->settings($this->cid())]);
    }

    public function saveSettings(Request $r): JsonResponse
    {
        $d = $r->validate([
            'legal_name' => ['nullable', 'string', 'max:200'],
            'gstin'      => ['nullable', 'string', 'regex:/^\d{2}[A-Z]{5}\d{4}[A-Z][A-Z\d]Z[A-Z\d]$/i'],
            'pan'        => ['nullable', 'string', 'regex:/^[A-Z]{5}\d{4}[A-Z]$/i'],
            'state_code' => ['nullable', 'string', 'size:2'],
            'address'    => ['nullable', 'string', 'max:500'],
            'pincode'    => ['nullable', 'digits:6'], 'city' => ['nullable', 'string', 'max:100'],
            'invoice_prefix' => ['nullable', 'string', 'max:10'],
            'fy_start_month' => ['nullable', 'integer', 'between:1,12'],
            'gst_registered' => ['nullable', 'boolean'],
            'petty_cash_limit' => ['nullable', 'numeric', 'min:0'], 'quotation_validity_days' => ['nullable', 'integer', 'between:1,365'],
        ]);
        if (!empty($d['gstin'])) { $d['gstin'] = strtoupper($d['gstin']); $d['state_code'] ??= substr($d['gstin'], 0, 2); }
        if (!empty($d['pan'])) { $d['pan'] = strtoupper($d['pan']); }
        $s = $this->svc->settings($this->cid());
        if (isset($d['fy_start_month']) && (int) $d['fy_start_month'] !== (int) $s->fy_start_month) {
            if (AccFiscalYear::where('company_id', $this->cid())->where('status', 'closed')->exists()) {
                return response()->json(['message' => 'The financial year start cannot change once a year has been closed.'], 422);
            }
            AccFiscalYear::where('company_id', $this->cid())->delete(); // open years are regenerated on the new calendar
        }
        $s->update(array_filter($d, fn ($v) => $v !== null) + ['gstin' => $d['gstin'] ?? null, 'pan' => $d['pan'] ?? null]);
        return response()->json(['message' => 'Saved.', 'settings' => $s->fresh()]);
    }

    // ─── Ledgers ──────────────────────────────────────────────────────────────
    public function ledgers(Request $r): JsonResponse
    {
        $cid = $this->cid();
        $this->svc->seedDefaults($cid);
        $bal = $this->svc->balances($cid);
        $rows = AccLedger::where('company_id', $cid)
            ->when($r->group, fn ($q, $g) => $q->whereIn('group', explode(',', $g)))
            ->when($r->nature, fn ($q, $n) => $q->where('nature', $n))
            ->when($r->search, fn ($q, $s) => $q->where('name', 'like', "%{$s}%"))
            ->orderByRaw('code IS NULL')->orderBy('code')->orderBy('name')->get()
            ->map(fn ($l) => $l->toArray() + ['balance' => $bal[$l->id]['balance'] ?? 0]);
        return response()->json(['ledgers' => $rows]);
    }

    private function ledgerRules(?int $id = null): array
    {
        return [
            'name'            => ['required', 'string', 'max:150', Rule::unique('acc_ledgers', 'name')->where('company_id', $this->cid())->ignore($id)],
            'nature'          => ['required', Rule::in(['asset', 'liability', 'income', 'expense', 'equity'])],
            'group'           => ['required', Rule::in(AccountsService::GROUPS)],
            'code'            => ['nullable', 'digits_between:4,8', Rule::unique('acc_ledgers', 'code')->where('company_id', $this->cid())->ignore($id)],
            'credit_limit'    => ['nullable', 'numeric', 'min:0'], 'credit_days' => ['nullable', 'integer', 'between:0,365'], 'credit_hold' => ['nullable', 'boolean'],
            'opening_balance' => ['nullable', 'numeric', 'min:0'],
            'opening_type'    => ['nullable', Rule::in(['dr', 'cr'])],
            'gstin'           => ['nullable', 'string', 'regex:/^\d{2}[A-Z]{5}\d{4}[A-Z][A-Z\d]Z[A-Z\d]$/i'],
            'state_code'      => ['nullable', 'string', 'size:2'],
            'phone' => ['nullable', 'string', 'max:20'], 'email' => ['nullable', 'email', 'max:150'],
            'address' => ['nullable', 'string', 'max:500'], 'pan' => ['nullable', 'string', 'max:10'],
            'pincode' => ['nullable', 'digits:6'], 'city' => ['nullable', 'string', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function storeLedger(Request $r): JsonResponse
    {
        $d = $r->validate($this->ledgerRules());
        $d = $this->normalizeLedger($d);
        $d['code'] ??= ChartOfAccounts::nextCode($this->cid(), $d['nature'], $d['group']);
        $l = AccLedger::create($d + ['company_id' => $this->cid()]);
        return response()->json(['message' => 'Ledger created.', 'ledger' => $l], 201);
    }

    public function updateLedger(Request $r, int $id): JsonResponse
    {
        $l = AccLedger::where('company_id', $this->cid())->findOrFail($id);
        $d = $this->normalizeLedger($r->validate($this->ledgerRules($id)));
        if (!array_key_exists('code', $d) || $d['code'] === null) { unset($d['code']); } // never blank out an account code
        if ($l->system_key) { unset($d['nature'], $d['group']); } // the engine posts to these — their type is fixed
        $l->update($d);
        return response()->json(['message' => 'Ledger updated.', 'ledger' => $l->fresh()]);
    }

    public function destroyLedger(int $id): JsonResponse
    {
        $l = AccLedger::where('company_id', $this->cid())->findOrFail($id);
        if ($l->system_key) { return response()->json(['message' => 'System ledgers cannot be deleted.'], 422); }
        if (AccEntry::where('ledger_id', $id)->exists()) { return response()->json(['message' => 'This ledger has transactions — deactivate it instead.'], 422); }
        $l->delete();
        return response()->json(['message' => 'Deleted.']);
    }

    private function normalizeLedger(array $d): array
    {
        if (!empty($d['gstin'])) { $d['gstin'] = strtoupper($d['gstin']); $d['state_code'] ??= substr($d['gstin'], 0, 2); }
        $d['opening_balance'] = $d['opening_balance'] ?? 0;
        $d['opening_type'] = $d['opening_type'] ?? (in_array($d['nature'] ?? 'asset', ['asset', 'expense'], true) ? 'dr' : 'cr');
        return $d;
    }

    // ─── Items (stock) ────────────────────────────────────────────────────────
    public function items(Request $r): JsonResponse
    {
        $cid = $this->cid();
        $stock = collect($this->svc->stockSummary($cid, now()->toDateString())['rows'])->keyBy('item_id');
        $rows = AccItem::where('company_id', $cid)->when($r->search, fn ($q, $s) => $q->where('name', 'like', "%{$s}%"))->orderBy('name')->get()
            ->map(fn ($i) => $i->toArray() + ['stock_qty' => $stock[$i->id]['qty'] ?? null, 'stock_value' => $stock[$i->id]['value'] ?? null, 'low' => $stock[$i->id]['low'] ?? false]);
        return response()->json(['items' => $rows]);
    }

    private function itemRules(?int $id = null): array
    {
        return [
            'name' => ['required', 'string', 'max:150', Rule::unique('acc_items', 'name')->where('company_id', $this->cid())->ignore($id)],
            'sku' => ['nullable', 'string', 'max:40'], 'hsn' => ['nullable', 'string', 'max:10'], 'unit' => ['nullable', 'string', 'max:10'],
            'gst_rate' => ['nullable', 'numeric', 'between:0,40'], 'sale_rate' => ['nullable', 'numeric', 'min:0'], 'purchase_rate' => ['nullable', 'numeric', 'min:0'],
            'track_stock' => ['nullable', 'boolean'], 'opening_qty' => ['nullable', 'numeric', 'min:0'], 'opening_value' => ['nullable', 'numeric', 'min:0'],
            'reorder_level' => ['nullable', 'numeric', 'min:0'], 'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function storeItem(Request $r): JsonResponse
    {
        $i = AccItem::create($r->validate($this->itemRules()) + ['company_id' => $this->cid()]);
        return response()->json(['message' => 'Item created.', 'item' => $i], 201);
    }

    public function updateItem(Request $r, int $id): JsonResponse
    {
        $i = AccItem::where('company_id', $this->cid())->findOrFail($id);
        $i->update($r->validate($this->itemRules($id)));
        return response()->json(['message' => 'Item updated.', 'item' => $i->fresh()]);
    }

    public function destroyItem(int $id): JsonResponse
    {
        $i = AccItem::where('company_id', $this->cid())->findOrFail($id);
        if (DB::table('acc_voucher_items')->where('item_id', $id)->exists()) { return response()->json(['message' => 'This item is used in vouchers — mark it inactive instead.'], 422); }
        $i->delete();
        return response()->json(['message' => 'Deleted.']);
    }

    // ─── Vouchers ─────────────────────────────────────────────────────────────
    public function vouchers(Request $r): JsonResponse
    {
        $cid = $this->cid();
        [$from, $to] = $this->range($r);
        $q = AccVoucher::with(['party:id,name', 'ledger:id,name', 'account:id,name'])
            ->where('company_id', $cid)->whereBetween('date', [$from, $to])
            ->when($r->type, fn ($q, $t) => $q->whereIn('type', explode(',', $t)))
            ->when($r->sub_type, fn ($q, $t) => $q->whereIn('sub_type', explode(',', $t)))
            ->when($r->party, fn ($q, $p) => $q->where('party_ledger_id', $p))
            ->when($r->search, fn ($q, $s) => $q->where(fn ($w) => $w->where('number', 'like', "%{$s}%")->orWhere('reference_no', 'like', "%{$s}%")->orWhere('narration', 'like', "%{$s}%")));
        $totals = (clone $q)->where('is_cancelled', false)->selectRaw('type, SUM(total) t')->groupBy('type')->pluck('t', 'type');
        $page = $q->orderByDesc('date')->orderByDesc('id')->paginate((int) $r->query('per_page', 25));
        return response()->json($page->toArray() + ['totals' => $totals]);
    }

    public function showVoucher(int $id): JsonResponse
    {
        $v = AccVoucher::with(['items', 'entries.ledger:id,name', 'party', 'ledger:id,name', 'account:id,name'])->where('company_id', $this->cid())->findOrFail($id);
        $alloc = \App\Modules\Accounts\Models\AccAllocation::where('company_id', $this->cid())
            ->where(fn ($q) => $q->where('payment_voucher_id', $id)->orWhere('invoice_voucher_id', $id))->get();
        $nums = AccVoucher::whereIn('id', $alloc->pluck('payment_voucher_id')->merge($alloc->pluck('invoice_voucher_id')))->pluck('number', 'id');
        $v->setAttribute('allocations', $alloc->map(fn ($a) => $a->toArray() + ['payment_number' => $nums[$a->payment_voucher_id] ?? null, 'invoice_number' => $nums[$a->invoice_voucher_id] ?? null]));
        if (PaymentService::isDocument($v)) { $v->setAttribute('outstanding', $this->payments->outstandingOf($v)); }
        return response()->json(['voucher' => $v, 'company' => $this->svc->settings($this->cid())]);
    }

    private function voucherRules(): array
    {
        return [
            'type' => ['required', Rule::in(AccountsService::TYPES)],
            'date' => ['required', 'date'],
            'party_ledger_id' => ['nullable', 'integer'], 'ledger_id' => ['nullable', 'integer'], 'account_ledger_id' => ['nullable', 'integer'],
            'place_of_supply' => ['nullable', 'string', 'size:2'],
            'amount' => ['nullable', 'numeric', 'min:0'], 'gst_rate' => ['nullable', 'numeric', 'between:0,40'],
            'description' => ['nullable', 'string', 'max:255'], 'hsn' => ['nullable', 'string', 'max:10'],
            'reference_no' => ['nullable', 'string', 'max:60'], 'narration' => ['nullable', 'string', 'max:1000'],
            'sub_type' => ['nullable', Rule::in(AccountsService::JOURNAL_SUBTYPES)], 'due_date' => ['nullable', 'date'],
            'reverse_on' => ['nullable', 'date'], 'credit_override' => ['nullable', 'boolean'],
            'is_advance' => ['nullable', 'boolean'], 'auto_allocate' => ['nullable', 'boolean'],
            'allocations' => ['nullable', 'array', 'max:200'], 'allocations.*.voucher_id' => ['required_with:allocations', 'integer'], 'allocations.*.amount' => ['required_with:allocations', 'numeric', 'min:0'],
            'tds_section' => ['nullable', 'string', 'max:10'], 'tds_rate' => ['nullable', 'numeric', 'between:0,30'],
            'items' => ['nullable', 'array', 'max:100'],
            'items.*.item_id' => ['nullable', 'integer'], 'items.*.description' => ['nullable', 'string', 'max:255'], 'items.*.hsn' => ['nullable', 'string', 'max:10'],
            'items.*.qty' => ['nullable', 'numeric', 'min:0'], 'items.*.rate' => ['nullable', 'numeric', 'min:0'], 'items.*.gst_rate' => ['nullable', 'numeric', 'between:0,40'],
            'entries' => ['nullable', 'array', 'max:50'],
            'entries.*.ledger_id' => ['nullable', 'integer'], 'entries.*.debit' => ['nullable', 'numeric', 'min:0'], 'entries.*.credit' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function storeVoucher(Request $r): JsonResponse
    {
        $v = $this->payments->save($this->cid(), auth()->id(), $r->validate($this->voucherRules()));
        return response()->json(['message' => "{$v->number} saved.", 'voucher' => $v], 201);
    }

    public function updateVoucher(Request $r, int $id): JsonResponse
    {
        $existing = AccVoucher::where('company_id', $this->cid())->findOrFail($id);
        if ($existing->source_type) { return response()->json(['message' => 'This entry was generated by the system (' . $existing->source_type . ') and cannot be edited here.'], 422); }
        $d = $r->validate($this->voucherRules());
        $d['type'] = $existing->type; // a voucher's type is fixed once created
        $v = $this->payments->save($this->cid(), auth()->id(), $d, $existing);
        return response()->json(['message' => "{$v->number} updated.", 'voucher' => $v]);
    }

    public function destroyVoucher(int $id): JsonResponse
    {
        $v = AccVoucher::where('company_id', $this->cid())->findOrFail($id);
        if ($v->source_type) { return response()->json(['message' => 'This entry was generated by the system (' . $v->source_type . ') — undo it from its own screen.'], 422); }
        $this->payments->delete($this->cid(), $v);
        return response()->json(['message' => 'Voucher deleted.']);
    }

    public function nextNumber(Request $r): JsonResponse
    {
        $t = $r->validate(['type' => ['required', Rule::in(AccountsService::TYPES)]])['type'];
        return response()->json(['number' => $this->svc->nextNumber($this->cid(), $t)]);
    }

    // ─── Reports ──────────────────────────────────────────────────────────────
    public function dashboard(Request $r): JsonResponse
    {
        $this->svc->seedDefaults($this->cid());
        [$from, $to] = $this->range($r);
        $cid = $this->cid();
        $rcv = $this->payments->documents($cid, 'receivable', ['status' => 'open']); $pay = $this->payments->documents($cid, 'payable', ['status' => 'open']);
        return response()->json($this->svc->dashboard($cid, $from, $to) + [
            'overdue_receivable' => $rcv['total_overdue'], 'overdue_payable' => $pay['total_overdue'],
            'open_invoices' => $rcv['count_open'], 'open_bills' => $pay['count_open'],
        ]);
    }

    public function report(Request $r, string $name)
    {
        $cid = $this->cid();
        $this->svc->seedDefaults($cid);
        [$from, $to] = $this->range($r);
        $data = match ($name) {
            'trial-balance' => $this->svc->trialBalance($cid, $to),
            'profit-loss'   => $this->svc->profitLoss($cid, $from, $to),
            'balance-sheet' => $this->svc->balanceSheet($cid, $to),
            'tds'           => $this->svc->tdsSummary($cid, $from, $to),
            'stock'         => $this->svc->stockSummary($cid, $to),
            'comparison'    => $this->svc->comparison($cid, $from, $to, $r->query('compare_from'), $r->query('compare_to')),
            'ageing-receivable' => $this->payments->ageing($cid, 'receivable', $to),
            'ageing-payable'    => $this->payments->ageing($cid, 'payable', $to),
            'receivables'   => $this->svc->outstanding($cid, 'receivable'),
            'payables'      => $this->svc->outstanding($cid, 'payable'),
            'ledger'        => $this->svc->ledgerStatement($cid, (int) $r->query('ledger_id'), $from, $to),
            'day-book'      => $this->dayBook($cid, $from, $to),
            'bank-reconciliation' => $this->svc->bankReconciliation($cid, (int) $r->query('ledger_id'), $to),
            default         => abort(404),
        };
        return $r->query('format') === 'csv' ? $this->csv($name, $data) : response()->json($data);
    }

    // GET /accounts/einvoice?voucher_id=… | from&to=… — JSON for upload to the government e-invoice portal (IRP)
    public function einvoice(Request $r)
    {
        $cid = $this->cid();
        [$from, $to] = $this->range($r);
        $q = AccVoucher::with(['items', 'party'])->where('company_id', $cid)->where('is_cancelled', false)->whereIn('type', ['sales', 'credit_note', 'debit_note'])
            ->when($r->query('voucher_id'), fn ($q, $id) => $q->where('id', $id), fn ($q) => $q->whereBetween('date', [$from, $to]))->orderBy('date')->orderBy('id');
        $set = $this->svc->settings($cid);
        $docs = []; $problems = [];
        foreach ($q->get() as $v) {
            [$doc, $missing] = $this->svc->einvoiceJson($v, $set);
            if ($missing) { $problems[] = ['number' => $v->number, 'missing' => $missing]; continue; }
            $docs[] = $doc;
        }
        if ($r->query('voucher_id')) {
            if ($problems) { return response()->json(['message' => $problems[0]['number'] . ' cannot be exported: ' . implode('; ', $problems[0]['missing'])], 422); }
            return response()->streamDownload(fn () => print(json_encode($docs[0], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)), 'einvoice-' . $docs[0]['DocDtls']['No'] . '.json', ['Content-Type' => 'application/json']);
        }
        if ($r->query('format') === 'json') {
            return response()->streamDownload(fn () => print(json_encode($docs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)), 'einvoice-bulk.json', ['Content-Type' => 'application/json']);
        }
        return response()->json(['count' => count($docs), 'skipped' => $problems]);
    }

    // POST /accounts/reconcile — { entry_ids: [..], date: 'Y-m-d'|null }
    public function reconcile(Request $r): JsonResponse
    {
        $d = $r->validate(['entry_ids' => ['required', 'array', 'min:1', 'max:500'], 'entry_ids.*' => ['integer'], 'date' => ['nullable', 'date']]);
        $n = $this->svc->reconcile($this->cid(), $d['entry_ids'], $d['date'] ?? null);
        return response()->json(['message' => $n . ($d['date'] ?? null ? ' entries marked as cleared.' : ' entries un-reconciled.'), 'updated' => $n]);
    }

    private function dayBook(int $cid, string $from, string $to): array
    {
        $rows = AccVoucher::with(['party:id,name', 'ledger:id,name', 'account:id,name'])
            ->where('company_id', $cid)->whereBetween('date', [$from, $to])->orderBy('date')->orderBy('id')->get()
            ->map(fn ($v) => ['date' => $v->date->toDateString(), 'id' => $v->id, 'type' => $v->type, 'number' => $v->number,
                              'particulars' => $v->party?->name ?? $v->ledger?->name ?? $v->account?->name, 'narration' => $v->narration, 'total' => $v->total]);
        return ['from' => $from, 'to' => $to, 'rows' => $rows, 'total' => round($rows->sum('total'), 2)];
    }

    // ─── GST ──────────────────────────────────────────────────────────────────
    public function gst(Request $r)
    {
        $cid = $this->cid();
        $this->svc->seedDefaults($cid);
        [$from, $to] = $this->range($r);
        $data = $this->svc->gst($cid, $from, $to);
        if ($r->query('format') !== 'csv') { return response()->json($data); }
        $part = $r->query('part', 'b2b');
        return $this->csv("gst-{$part}", ['rows' => match ($part) {
            'b2c' => $data['b2c'], 'notes' => $data['notes'], 'purchases' => $data['purchases'], 'hsn' => $data['hsn'], 'rate' => $data['by_rate'], default => $data['b2b'],
        }]);
    }

    /** Flat CSV of whichever `rows` a report returned. */
    private function csv(string $name, array $data): StreamedResponse
    {
        $rows = collect($data['rows'] ?? [])->map(fn ($x) => (array) $x)->all();
        if (!$rows && isset($data['income'])) { // profit & loss has two lists
            $rows = array_merge(
                array_map(fn ($x) => ['section' => 'Income'] + (array) $x, $data['income']),
                array_map(fn ($x) => ['section' => 'Expense'] + (array) $x, $data['expense']),
            );
        }
        if (!$rows && isset($data['assets'])) {
            $rows = array_merge(
                array_map(fn ($x) => ['section' => 'Assets'] + (array) $x, $data['assets']),
                array_map(fn ($x) => ['section' => 'Liabilities'] + (array) $x, $data['liabilities']),
            );
        }
        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            if ($rows) { fputcsv($out, array_keys($rows[0])); }
            foreach ($rows as $row) { fputcsv($out, array_map(fn ($v) => is_scalar($v) || $v === null ? $v : json_encode($v), $row)); }
            fclose($out);
        }, "{$name}.csv", ['Content-Type' => 'text/csv']);
    }
}
