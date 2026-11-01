<?php

namespace App\Modules\Accounts\Support;

use App\Modules\Accounts\Models\AccAllocation;
use App\Modules\Accounts\Models\AccLedger;
use App\Modules\Accounts\Models\AccVoucher;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Receivables and payables, invoice by invoice.
 *
 *   receivable documents: sales, income booked to a customer   — settled by receipts, credit notes
 *   payable documents:    purchase, expense booked to a supplier — settled by payments, debit notes
 *
 * A settlement is recorded as allocations. Whatever is not allocated stays "on account" on the party's ledger, and an
 * advance (receipt/payment flagged is_advance) waits in its own liability/asset ledger until it is applied to a bill.
 */
class PaymentService
{
    public const BUCKETS = ['current' => 'Not yet due', 'd1_30' => '1–30 days', 'd31_60' => '31–60 days', 'd61_90' => '61–90 days', 'd90p' => 'Over 90 days'];
    private const FAMILY = [
        'receivable' => ['docs' => ['sales', 'income'], 'settle' => ['receipt', 'credit_note']],
        'payable'    => ['docs' => ['purchase', 'expense'], 'settle' => ['payment', 'debit_note']],
    ];

    public function __construct(private readonly AccountsService $svc) {}

    private function fail(string $field, string $msg): never
    {
        throw ValidationException::withMessages([$field => [$msg]]);
    }

    public static function kindOfDoc(string $type): ?string
    {
        foreach (self::FAMILY as $k => $f) { if (in_array($type, $f['docs'], true)) { return $k; } }
        return null;
    }

    public static function kindOfSettlement(string $type): ?string
    {
        foreach (self::FAMILY as $k => $f) { if (in_array($type, $f['settle'], true)) { return $k; } }
        return null;
    }

    /** True when a voucher is an invoice/bill owed on credit (an expense paid from cash/bank is not). */
    public static function isDocument(AccVoucher $v): bool
    {
        if (!self::kindOfDoc($v->type) || $v->is_cancelled || !$v->party_ledger_id) { return false; }
        return !in_array($v->type, ['income', 'expense'], true) || !$v->account_ledger_id;
    }

    /** Amount the party actually owes on a document (we deduct TDS from what we pay suppliers). */
    public static function dueOf(AccVoucher $v): float
    {
        return round((float) $v->total - ($v->type === 'sales' || $v->type === 'income' ? 0 : (float) $v->tds_amount), 2);
    }

    // ─── Saving with allocations ──────────────────────────────────────────────
    public function save(int $cid, ?int $userId, array $d, ?AccVoucher $existing = null): AccVoucher
    {
        return DB::transaction(function () use ($cid, $userId, $d, $existing) {
            $v = $this->svc->saveVoucher($cid, $userId, $d, $existing);
            if (self::kindOfSettlement($v->type)) { $this->syncAllocations($cid, $v, $d, $existing !== null); }
            elseif (self::isDocument($v)) { $this->guardDocument($cid, $v); }
            elseif ($existing) { $this->guardDocument($cid, $v); }
            return $v->fresh(['items', 'party:id,name,gstin', 'ledger:id,name', 'account:id,name']);
        });
    }

    public function delete(int $cid, AccVoucher $v): void
    {
        if (AccAllocation::where('invoice_voucher_id', $v->id)->exists()) {
            $this->fail('voucher', "{$v->number} has payments settled against it — remove those first.");
        }
        if ($v->is_advance && AccAllocation::where('payment_voucher_id', $v->id)->exists()) {
            $this->fail('voucher', "{$v->number} is an advance that has been applied to bills — un-apply it first.");
        }
        DB::transaction(function () use ($cid, $v) {
            $this->svc->assertOpen($cid, $v->date);
            AccAllocation::where('payment_voucher_id', $v->id)->delete();
            $this->svc->deleteVoucher($cid, $v);
        });
    }

    /** An invoice may not be edited below what has already been settled against it, nor moved to another party. */
    private function guardDocument(int $cid, AccVoucher $v): void
    {
        $settled = (float) AccAllocation::where('invoice_voucher_id', $v->id)->sum('amount');
        if ($settled <= 0) { return; }
        if (!self::isDocument($v)) { $this->fail('voucher', "{$v->number} has payments settled against it and must stay an open invoice."); }
        if (self::dueOf($v) + 0.004 < $settled) {
            $this->fail('total', sprintf('%s already has %s settled against it — the total cannot be lower than that.', $v->number, number_format($settled, 2)));
        }
        $partyIds = AccVoucher::whereIn('id', AccAllocation::where('invoice_voucher_id', $v->id)->pluck('payment_voucher_id'))->pluck('party_ledger_id')->unique();
        if ($partyIds->contains(fn ($p) => (int) $p !== (int) $v->party_ledger_id)) { $this->fail('party_ledger_id', 'Payments are already settled against this invoice for the original party.'); }
    }

    private function syncAllocations(int $cid, AccVoucher $v, array $d, bool $isEdit): void
    {
        $kind = self::kindOfSettlement($v->type);
        $old = AccAllocation::where('payment_voucher_id', $v->id)->whereNull('journal_voucher_id')->get();

        if ($v->is_advance) {
            if (!empty($d['allocations'])) { $this->fail('allocations', 'An advance is held until it is applied to a bill — it cannot be allocated when it is recorded.'); }
            return;
        }
        if (array_key_exists('allocations', $d)) { $want = collect($d['allocations'] ?? []); }
        elseif (!empty($d['auto_allocate'])) { $want = null; }
        elseif ($isEdit) { $want = $old->map(fn ($a) => ['voucher_id' => $a->invoice_voucher_id, 'amount' => $a->amount]); }
        else { $want = collect(); }

        AccAllocation::where('payment_voucher_id', $v->id)->whereNull('journal_voucher_id')->delete();
        if ($v->party_ledger_id === null) { return; }

        if ($want === null) { $want = $this->fifo($cid, $kind, (int) $v->party_ledger_id, (float) $v->total, $v->date->toDateString()); }

        $merged = [];
        foreach ($want as $a) { $id = (int) ($a['voucher_id'] ?? 0); $merged[$id] = ($merged[$id] ?? 0) + round((float) ($a['amount'] ?? 0), 2); }
        $sum = 0.0;
        foreach ($merged as $invId => $amt) {
            if ($amt <= 0) { continue; }
            $inv = AccVoucher::where('company_id', $cid)->find($invId);
            if (!$inv || self::kindOfDoc($inv->type) !== $kind || !self::isDocument($inv)) { $this->fail('allocations', 'One of the selected invoices cannot be settled with this ' . str_replace('_', ' ', $v->type) . '.'); }
            if ((int) $inv->party_ledger_id !== (int) $v->party_ledger_id) { $this->fail('allocations', "{$inv->number} belongs to a different party."); }
            if ($v->date->lt($inv->date)) { $this->fail('allocations', "{$inv->number} is dated after this {$v->type} ({$inv->date->format('d M Y')})."); }
            $open = $this->outstandingOf($inv);
            if ($amt > $open + 0.004) { $this->fail('allocations', sprintf('%s has only %s outstanding (you allocated %s).', $inv->number, number_format($open, 2), number_format($amt, 2))); }
            $sum += $amt;
            AccAllocation::create(['company_id' => $cid, 'payment_voucher_id' => $v->id, 'invoice_voucher_id' => $inv->id, 'amount' => $amt, 'date' => $v->date->toDateString()]);
        }
        if ($sum > (float) $v->total + 0.004) {
            $this->fail('allocations', sprintf('Allocated %s is more than the %s amount of %s.', number_format($sum, 2), str_replace('_', ' ', $v->type), number_format((float) $v->total, 2)));
        }
    }

    /** Oldest-due-first allocation of an amount over a party's open invoices. */
    public function fifo(int $cid, string $kind, int $partyId, float $amount, ?string $asOf = null): Collection
    {
        $left = $amount; $out = collect();
        foreach ($this->documents($cid, $kind, ['party' => $partyId, 'status' => 'open', 'as_of' => $asOf])['rows'] as $r) {
            if ($left <= 0.004) { break; }
            $take = round(min($left, $r['outstanding']), 2);
            $out->push(['voucher_id' => $r['voucher_id'], 'amount' => $take]);
            $left -= $take;
        }
        return $out;
    }

    public function outstandingOf(AccVoucher $inv): float
    {
        return round(self::dueOf($inv) - (float) AccAllocation::where('invoice_voucher_id', $inv->id)->sum('amount'), 2);
    }

    // ─── Open documents ───────────────────────────────────────────────────────
    /**
     * Every invoice/bill with what has been settled and what is still owed, as on `as_of` (default today).
     * filters: party, status (open|overdue|partial|paid|all, default all), search
     */
    public function documents(int $cid, string $kind, array $f = []): array
    {
        $asOf = Carbon::parse($f['as_of'] ?? now()->toDateString())->startOfDay();
        $docs = AccVoucher::with('party:id,name,phone,credit_days')->where('company_id', $cid)->where('is_cancelled', false)
            ->whereIn('type', self::FAMILY[$kind]['docs'])->whereNotNull('party_ledger_id')->where('date', '<=', $asOf->toDateString())
            ->when(!empty($f['party']), fn ($q) => $q->where('party_ledger_id', $f['party']))
            ->when(!empty($f['search']), fn ($q) => $q->where(fn ($w) => $w->where('number', 'like', '%' . $f['search'] . '%')->orWhere('reference_no', 'like', '%' . $f['search'] . '%')))
            ->orderBy('due_date')->orderBy('date')->orderBy('id')->get()->filter(fn ($v) => self::isDocument($v));

        $settled = AccAllocation::where('company_id', $cid)->where('date', '<=', $asOf->toDateString())->whereIn('invoice_voucher_id', $docs->pluck('id'))
            ->selectRaw('invoice_voucher_id i, SUM(amount) a')->groupBy('invoice_voucher_id')->pluck('a', 'i');

        $rows = [];
        foreach ($docs as $v) {
            $due = self::dueOf($v); $paid = round((float) ($settled[$v->id] ?? 0), 2); $out = round($due - $paid, 2);
            $dueDate = $v->due_date ?? $v->date;
            $late = $out > 0.004 && $dueDate->lt($asOf) ? (int) $dueDate->diffInDays($asOf) : 0;
            $status = $out <= 0.004 ? 'paid' : ($late > 0 ? 'overdue' : ($paid > 0 ? 'partial' : 'open'));
            $rows[] = ['voucher_id' => $v->id, 'type' => $v->type, 'number' => $v->number, 'reference_no' => $v->reference_no, 'date' => $v->date->toDateString(),
                       'due_date' => $dueDate->toDateString(), 'party_id' => $v->party_ledger_id, 'party' => $v->party?->name, 'phone' => $v->party?->phone,
                       'total' => $due, 'settled' => $paid, 'outstanding' => max(0.0, $out), 'days_overdue' => $late, 'status' => $status,
                       'bucket' => $out <= 0.004 ? null : self::bucket($late)];
        }
        $want = $f['status'] ?? 'all';
        if ($want === 'open') { $rows = array_values(array_filter($rows, fn ($r) => $r['outstanding'] > 0.004)); }
        elseif ($want !== 'all') { $rows = array_values(array_filter($rows, fn ($r) => $r['status'] === $want)); }

        $open = array_filter($rows, fn ($r) => $r['outstanding'] > 0.004);
        return ['kind' => $kind, 'as_on' => $asOf->toDateString(), 'rows' => $rows,
                'total_outstanding' => round(array_sum(array_column($open, 'outstanding')), 2),
                'total_overdue' => round(array_sum(array_map(fn ($r) => $r['days_overdue'] > 0 ? $r['outstanding'] : 0, $open)), 2),
                'count_open' => count($open)];
    }

    public static function bucket(int $daysLate): string
    {
        return match (true) { $daysLate <= 0 => 'current', $daysLate <= 30 => 'd1_30', $daysLate <= 60 => 'd31_60', $daysLate <= 90 => 'd61_90', default => 'd90p' };
    }

    /** Overdue amount per party, for the credit screen. */
    public function overdueByParty(int $cid, string $kind): array
    {
        $out = [];
        foreach ($this->documents($cid, $kind, ['status' => 'open'])['rows'] as $r) {
            if ($r['days_overdue'] > 0) {
                $out[$r['party_id']]['overdue'] = round(($out[$r['party_id']]['overdue'] ?? 0) + $r['outstanding'], 2);
                $out[$r['party_id']]['oldest'] = max($out[$r['party_id']]['oldest'] ?? 0, $r['days_overdue']);
            }
        }
        return $out;
    }

    // ─── Ageing ───────────────────────────────────────────────────────────────
    public function ageing(int $cid, string $kind, ?string $asOf = null): array
    {
        $asOf ??= now()->toDateString();
        $docs = $this->documents($cid, $kind, ['status' => 'open', 'as_of' => $asOf])['rows'];
        $sign = $kind === 'payable' ? -1 : 1;
        $parties = [];
        foreach ($docs as $r) {
            $p = &$parties[$r['party_id']];
            $p ??= ['party_id' => $r['party_id'], 'name' => $r['party'], 'phone' => $r['phone'], 'invoices' => 0] + array_fill_keys(array_keys(self::BUCKETS), 0.0) + ['outstanding' => 0.0];
            $p[$r['bucket']] = round($p[$r['bucket']] + $r['outstanding'], 2);
            $p['outstanding'] = round($p['outstanding'] + $r['outstanding'], 2);
            $p['invoices']++;
            unset($p);
        }
        // What the ledger says vs. what the open invoices explain: the gap is opening balance (+) or unallocated payments on account (−).
        foreach (AccLedger::where('company_id', $cid)->whereIn('id', array_unique(array_merge(array_keys($parties), AccLedger::where('company_id', $cid)->where('group', $kind === 'payable' ? 'creditor' : 'debtor')->pluck('id')->all())))->get() as $l) {
            $bal = $sign * $this->svc->ledgerBalance($cid, $l->id, null, $asOf);
            $open = $parties[$l->id]['outstanding'] ?? 0.0;
            $gap = round($bal - $open, 2);
            if (!isset($parties[$l->id]) && abs($bal) < 0.005) { continue; }
            $parties[$l->id] ??= ['party_id' => $l->id, 'name' => $l->name, 'phone' => $l->phone, 'invoices' => 0] + array_fill_keys(array_keys(self::BUCKETS), 0.0) + ['outstanding' => 0.0];
            $parties[$l->id]['ledger_balance'] = round($bal, 2);
            $parties[$l->id]['on_account'] = $gap;   // >0 opening/unbilled balance, <0 payments received/made but not yet matched to an invoice
        }
        $rows = array_values($parties);
        usort($rows, fn ($a, $b) => $b['outstanding'] <=> $a['outstanding']);
        $tot = array_fill_keys(array_keys(self::BUCKETS), 0.0);
        foreach ($rows as $r) { foreach ($tot as $k => $_) { $tot[$k] = round($tot[$k] + $r[$k], 2); } }
        return ['kind' => $kind, 'as_on' => $asOf, 'buckets' => self::BUCKETS, 'rows' => $rows, 'totals' => $tot + [
            'outstanding' => round(array_sum(array_column($rows, 'outstanding')), 2),
            'on_account' => round(array_sum(array_map(fn ($r) => $r['on_account'] ?? 0, $rows)), 2),
        ], 'advances' => $this->advances($cid, $kind)['total']];
    }

    // ─── Advances ─────────────────────────────────────────────────────────────
    /** Advance receipts/payments still waiting to be applied to a bill. */
    public function advances(int $cid, string $kind, ?int $partyId = null): array
    {
        $type = $kind === 'payable' ? 'payment' : 'receipt';
        $rows = AccVoucher::with('party:id,name')->where('company_id', $cid)->where('type', $type)->where('is_advance', true)->where('is_cancelled', false)
            ->when($partyId, fn ($q) => $q->where('party_ledger_id', $partyId))->orderBy('date')->get()
            ->map(function ($v) {
                $used = (float) AccAllocation::where('payment_voucher_id', $v->id)->sum('amount');
                return ['voucher_id' => $v->id, 'number' => $v->number, 'date' => $v->date->toDateString(), 'party_id' => $v->party_ledger_id, 'party' => $v->party?->name,
                        'amount' => $v->total, 'applied' => round($used, 2), 'remaining' => round($v->total - $used, 2)];
            })->filter(fn ($r) => $r['remaining'] > 0.004)->values();
        return ['kind' => $kind, 'rows' => $rows, 'total' => round($rows->sum('remaining'), 2)];
    }

    /** Move an advance onto a specific bill: Dr Customer Advances / Cr Customer (or Dr Supplier / Cr Advances to Suppliers). */
    public function applyAdvance(int $cid, ?int $userId, int $advanceId, int $invoiceId, float $amount, ?string $date = null): AccAllocation
    {
        $adv = AccVoucher::where('company_id', $cid)->where('is_advance', true)->find($advanceId);
        $inv = AccVoucher::where('company_id', $cid)->find($invoiceId);
        if (!$adv) { $this->fail('advance', 'That advance does not exist.'); }
        if (!$inv || !self::isDocument($inv)) { $this->fail('invoice', 'Choose an open invoice or bill.'); }
        $kind = self::kindOfSettlement($adv->type);
        if (self::kindOfDoc($inv->type) !== $kind) { $this->fail('invoice', $kind === 'receivable' ? 'An advance received can only be applied to a sales invoice.' : 'An advance paid can only be applied to a purchase bill.'); }
        if ((int) $adv->party_ledger_id !== (int) $inv->party_ledger_id) { $this->fail('invoice', 'The advance and the invoice belong to different parties.'); }
        $amount = round($amount, 2);
        $date = $date ?: now()->toDateString();
        if (Carbon::parse($date)->lt($inv->date) || Carbon::parse($date)->lt($adv->date)) { $this->fail('date', 'The adjustment cannot be dated before the invoice or the advance.'); }
        $left = $this->advances($cid, $kind, $adv->party_ledger_id)['rows']->firstWhere('voucher_id', $adv->id)['remaining'] ?? 0;
        if ($amount <= 0 || $amount > $left + 0.004) { $this->fail('amount', 'Only ' . number_format($left, 2) . ' of this advance is left to apply.'); }
        $open = $this->outstandingOf($inv);
        if ($amount > $open + 0.004) { $this->fail('amount', "{$inv->number} has only " . number_format($open, 2) . ' outstanding.'); }

        return DB::transaction(function () use ($cid, $userId, $adv, $inv, $amount, $date, $kind) {
            $holding = $this->svc->sys($cid, $kind === 'receivable' ? 'deferred_revenue' : 'supplier_advances');
            $entries = $kind === 'receivable'
                ? [['ledger_id' => $holding, 'debit' => $amount], ['ledger_id' => $adv->party_ledger_id, 'credit' => $amount]]
                : [['ledger_id' => $adv->party_ledger_id, 'debit' => $amount], ['ledger_id' => $holding, 'credit' => $amount]];
            $j = $this->svc->saveVoucher($cid, $userId, ['type' => 'journal', 'date' => $date, 'sub_type' => 'advance_adjust', 'source_type' => 'advance', 'source_id' => $adv->id,
                'entries' => $entries, 'narration' => "Advance {$adv->number} applied to {$inv->number}"]);
            return AccAllocation::create(['company_id' => $cid, 'payment_voucher_id' => $adv->id, 'invoice_voucher_id' => $inv->id, 'amount' => $amount, 'date' => $date, 'journal_voucher_id' => $j->id]);
        });
    }

    public function unapplyAdvance(int $cid, int $allocationId): void
    {
        $a = AccAllocation::where('company_id', $cid)->whereNotNull('journal_voucher_id')->findOrFail($allocationId);
        DB::transaction(function () use ($cid, $a) {
            $j = AccVoucher::where('company_id', $cid)->find($a->journal_voucher_id);
            if ($j) { $this->svc->deleteVoucher($cid, $j); }
            $a->delete();
        });
    }

    // ─── Payment report ───────────────────────────────────────────────────────
    public function report(int $cid, string $from, string $to): array
    {
        $vs = AccVoucher::with(['party:id,name', 'account:id,name,group'])->where('company_id', $cid)->where('is_cancelled', false)
            ->whereIn('type', ['receipt', 'payment'])->whereBetween('date', [$from, $to])->orderBy('date')->orderBy('id')->get();
        $alloc = AccAllocation::whereIn('payment_voucher_id', $vs->pluck('id'))->whereNull('journal_voucher_id')->selectRaw('payment_voucher_id p, SUM(amount) a')->groupBy('payment_voucher_id')->pluck('a', 'p');

        $rows = $vs->map(function ($v) use ($alloc) {
            $a = round((float) ($alloc[$v->id] ?? 0), 2);
            return ['voucher_id' => $v->id, 'date' => $v->date->toDateString(), 'number' => $v->number, 'direction' => $v->type === 'receipt' ? 'in' : 'out',
                    'party' => $v->party?->name, 'account' => $v->account?->name, 'mode' => $v->account?->group, 'reference_no' => $v->reference_no,
                    'amount' => $v->total, 'allocated' => $a, 'unallocated' => $v->is_advance ? 0.0 : round($v->total - $a, 2), 'advance' => $v->is_advance ? $v->total : 0.0];
        })->values();
        $in = $rows->where('direction', 'in'); $out = $rows->where('direction', 'out');
        $byAccount = $rows->groupBy('account')->map(fn ($g, $k) => ['account' => $k, 'received' => round($g->where('direction', 'in')->sum('amount'), 2),
            'paid' => round($g->where('direction', 'out')->sum('amount'), 2)])->values();
        return ['from' => $from, 'to' => $to, 'rows' => $rows, 'by_account' => $byAccount,
                'total_received' => round($in->sum('amount'), 2), 'total_paid' => round($out->sum('amount'), 2),
                'net' => round($in->sum('amount') - $out->sum('amount'), 2), 'advances_received' => round($in->sum('advance'), 2), 'advances_paid' => round($out->sum('advance'), 2)];
    }
}
