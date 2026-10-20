<?php

namespace App\Modules\Accounts\Support;

use App\Modules\Accounts\Models\AccEntry;
use App\Modules\Accounts\Models\AccLedger;
use App\Modules\Accounts\Models\AccSetting;
use App\Modules\Accounts\Models\AccVoucher;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Double-entry engine: every voucher is turned into balanced debit/credit rows in acc_entries,
 * and every report reads only those rows (plus ledger opening balances).
 */
class AccountsService
{
    public const TYPES = ['sales', 'purchase', 'receipt', 'payment', 'expense', 'income', 'journal', 'contra'];
    private const PREFIX = ['sales' => null, 'purchase' => 'PUR', 'receipt' => 'RCT', 'payment' => 'PAY', 'expense' => 'EXP', 'income' => 'INC', 'journal' => 'JV', 'contra' => 'CNT'];

    // [name, nature, group, system_key]
    private const DEFAULT_LEDGERS = [
        ['Cash in Hand', 'asset', 'cash', 'cash'],
        ['Bank Account', 'asset', 'bank', 'bank'],
        ['Sales', 'income', 'sales', 'sales'],
        ['Other Income', 'income', 'indirect_income', 'other_income'],
        ['Purchases', 'expense', 'purchase', 'purchase'],
        ['Office Rent', 'expense', 'indirect_expense', null],
        ['Salaries & Wages', 'expense', 'indirect_expense', null],
        ['Electricity & Water', 'expense', 'indirect_expense', null],
        ['Internet & Phone', 'expense', 'indirect_expense', null],
        ['Travel & Conveyance', 'expense', 'indirect_expense', null],
        ['Marketing & Advertising', 'expense', 'indirect_expense', null],
        ['Office Supplies & Stationery', 'expense', 'indirect_expense', null],
        ['Repairs & Maintenance', 'expense', 'indirect_expense', null],
        ['Professional & Legal Fees', 'expense', 'indirect_expense', null],
        ['Software & Subscriptions', 'expense', 'indirect_expense', null],
        ['Bank Charges', 'expense', 'indirect_expense', null],
        ['Miscellaneous Expenses', 'expense', 'indirect_expense', null],
        ['Output CGST', 'liability', 'gst_output', 'out_cgst'],
        ['Output SGST', 'liability', 'gst_output', 'out_sgst'],
        ['Output IGST', 'liability', 'gst_output', 'out_igst'],
        ['Input CGST', 'asset', 'gst_input', 'in_cgst'],
        ['Input SGST', 'asset', 'gst_input', 'in_sgst'],
        ['Input IGST', 'asset', 'gst_input', 'in_igst'],
        ['Capital Account', 'equity', 'capital', 'capital'],
        ['Drawings', 'equity', 'capital', null],
    ];

    public function settings(int $cid): AccSetting
    {
        return AccSetting::firstOrCreate(['company_id' => $cid]);
    }

    /** First visit: create the standard chart of accounts for this company. */
    public function seedDefaults(int $cid): void
    {
        if (AccLedger::where('company_id', $cid)->exists()) { return; }
        $this->settings($cid);
        foreach (self::DEFAULT_LEDGERS as [$name, $nature, $group, $key]) {
            AccLedger::create(['company_id' => $cid, 'name' => $name, 'nature' => $nature, 'group' => $group, 'system_key' => $key]);
        }
    }

    private function sys(int $cid, string $key): int
    {
        $id = AccLedger::where('company_id', $cid)->where('system_key', $key)->value('id');
        if (!$id) { // a seeded ledger was removed from an older chart — recreate it
            foreach (self::DEFAULT_LEDGERS as [$name, $nature, $group, $k]) {
                if ($k === $key) { return AccLedger::create(['company_id' => $cid, 'name' => $name, 'nature' => $nature, 'group' => $group, 'system_key' => $k])->id; }
            }
        }
        return (int) $id;
    }

    // ─── Voucher posting ──────────────────────────────────────────────────────
    public function nextNumber(int $cid, string $type): string
    {
        $prefix = self::PREFIX[$type] ?? strtoupper(substr($type, 0, 3));
        if ($type === 'sales') { $prefix = $this->settings($cid)->invoice_prefix ?: 'INV'; }
        $last = AccVoucher::where('company_id', $cid)->where('type', $type)->orderByDesc('id')->value('number');
        $n = $last && preg_match('/(\d+)$/', $last, $m) ? (int) $m[1] + 1 : 1;
        return $prefix . '-' . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
    }

    public function saveVoucher(int $cid, ?int $userId, array $d, ?AccVoucher $existing = null): AccVoucher
    {
        $type = $d['type'];
        $set  = $this->settings($cid);
        $this->seedDefaults($cid);

        return DB::transaction(function () use ($cid, $userId, $d, $existing, $type, $set) {
            $party   = !empty($d['party_ledger_id']) ? $this->ledgerOf($cid, (int) $d['party_ledger_id']) : null;
            $ledger  = !empty($d['ledger_id']) ? $this->ledgerOf($cid, (int) $d['ledger_id']) : null;
            $account = !empty($d['account_ledger_id']) ? $this->ledgerOf($cid, (int) $d['account_ledger_id']) : null;

            $rows = [];          // [ledger_id, debit, credit]
            $items = [];
            $taxable = $cgst = $sgst = $igst = 0.0;
            $inter = false;
            $pos = $d['place_of_supply'] ?? null;

            if (in_array($type, ['sales', 'purchase', 'expense', 'income'], true)) {
                $counter = $pos ?: ($party->state_code ?? null);
                $inter   = $counter && $set->state_code && $counter !== $set->state_code;
                $pos     = $counter;
                $lines   = $type === 'sales' || $type === 'purchase'
                    ? ($d['items'] ?? [])
                    : [['description' => $d['description'] ?? ($ledger->name ?? ucfirst($type)), 'hsn' => $d['hsn'] ?? null, 'qty' => 1, 'rate' => (float) ($d['amount'] ?? 0), 'gst_rate' => $d['gst_rate'] ?? 0]];

                foreach ($lines as $ln) {
                    $qty  = (float) ($ln['qty'] ?? 1);
                    $rate = (float) ($ln['rate'] ?? 0);
                    $amt  = round($qty * $rate, 2);
                    $gr   = in_array($type, ['sales', 'income'], true) && !$set->gst_registered ? 0.0 : (float) ($ln['gst_rate'] ?? 0);
                    $tax  = round($amt * $gr / 100, 2);
                    $c = $s = $i = 0.0;
                    if ($inter) { $i = $tax; } else { $c = round($tax / 2, 2); $s = round($tax - $c, 2); }
                    if ($amt <= 0) { continue; }
                    $items[] = ['description' => (string) ($ln['description'] ?? '—'), 'hsn' => $ln['hsn'] ?? null, 'qty' => $qty, 'rate' => $rate,
                                'amount' => $amt, 'gst_rate' => $gr, 'cgst' => $c, 'sgst' => $s, 'igst' => $i];
                    $taxable += $amt; $cgst += $c; $sgst += $s; $igst += $i;
                }
                if (!$items) { $this->fail('items', 'Enter an amount greater than zero.'); }
                $total = round($taxable + $cgst + $sgst + $igst, 2);
                $tax   = round($cgst + $sgst + $igst, 2);
                $itc   = $set->gst_registered; // an unregistered business cannot claim input credit — tax becomes part of the cost

                if ($type === 'sales') {
                    if (!$party) { $this->fail('party_ledger_id', 'Select the customer.'); }
                    $rows[] = [$party->id, $total, 0];
                    $rows[] = [$ledger?->id ?? $this->sys($cid, 'sales'), 0, $taxable];
                    $this->taxRows($cid, $rows, 'out', $cgst, $sgst, $igst, true);
                } elseif ($type === 'income') {
                    $to = $account ?? $party;
                    if (!$to || !$ledger) { $this->fail('ledger_id', 'Select the income ledger and where the money was received.'); }
                    $rows[] = [$to->id, $total, 0];
                    $rows[] = [$ledger->id, 0, $taxable];
                    $this->taxRows($cid, $rows, 'out', $cgst, $sgst, $igst, true);
                } else { // purchase | expense
                    $from = $type === 'purchase' ? $party : ($account ?? $party);
                    if (!$from) { $this->fail($type === 'purchase' ? 'party_ledger_id' : 'account_ledger_id', $type === 'purchase' ? 'Select the supplier.' : 'Select who/what it was paid from.'); }
                    $debitLedger = $ledger?->id ?? ($type === 'purchase' ? $this->sys($cid, 'purchase') : null);
                    if (!$debitLedger) { $this->fail('ledger_id', 'Select the expense ledger.'); }
                    $rows[] = [$debitLedger, $itc ? $taxable : $total, 0];
                    if ($itc) { $this->taxRows($cid, $rows, 'in', $cgst, $sgst, $igst, false); }
                    $rows[] = [$from->id, 0, $total];
                }
            } elseif (in_array($type, ['receipt', 'payment'], true)) {
                $total = round((float) ($d['amount'] ?? 0), 2);
                if ($total <= 0) { $this->fail('amount', 'Enter an amount greater than zero.'); }
                if (!$party || !$account) { $this->fail('party_ledger_id', 'Select the party and the cash/bank account.'); }
                $rows = $type === 'receipt'
                    ? [[$account->id, $total, 0], [$party->id, 0, $total]]
                    : [[$party->id, $total, 0], [$account->id, 0, $total]];
                $taxable = $total; $tax = 0.0;
            } elseif ($type === 'contra') {
                $total = round((float) ($d['amount'] ?? 0), 2);
                if ($total <= 0 || !$ledger || !$account) { $this->fail('amount', 'Select both accounts and enter an amount.'); }
                if ($ledger->id === $account->id) { $this->fail('ledger_id', 'Choose two different accounts.'); }
                $rows = [[$ledger->id, $total, 0], [$account->id, 0, $total]]; // to / from
                $taxable = $total; $tax = 0.0;
            } else { // journal
                $dr = $cr = 0.0;
                foreach ($d['entries'] ?? [] as $e) {
                    $de = round((float) ($e['debit'] ?? 0), 2); $ce = round((float) ($e['credit'] ?? 0), 2);
                    if (($de <= 0 && $ce <= 0) || empty($e['ledger_id'])) { continue; }
                    $this->ledgerOf($cid, (int) $e['ledger_id']);
                    $rows[] = [(int) $e['ledger_id'], $de, $ce]; $dr += $de; $cr += $ce;
                }
                if (count($rows) < 2) { $this->fail('entries', 'A journal needs at least two lines.'); }
                if (abs($dr - $cr) > 0.009) { $this->fail('entries', 'Debit (' . number_format($dr, 2) . ') and credit (' . number_format($cr, 2) . ') must be equal.'); }
                $total = round($dr, 2); $taxable = $total; $tax = 0.0;
            }

            $attrs = [
                'company_id' => $cid, 'type' => $type, 'date' => $d['date'],
                'party_ledger_id' => $party?->id, 'ledger_id' => $ledger?->id, 'account_ledger_id' => $account?->id,
                'place_of_supply' => $pos, 'is_interstate' => $inter,
                'taxable' => round($taxable, 2), 'cgst' => round($cgst, 2), 'sgst' => round($sgst, 2), 'igst' => round($igst, 2), 'total' => $total,
                'reference_no' => $d['reference_no'] ?? null, 'narration' => $d['narration'] ?? null,
            ];

            if ($existing) {
                $existing->update($attrs);
                $existing->items()->delete();
                AccEntry::where('voucher_id', $existing->id)->delete();
                $voucher = $existing;
            } else {
                $voucher = AccVoucher::create($attrs + ['number' => $this->nextNumber($cid, $type), 'created_by' => $userId]);
            }

            foreach ($items as $it) { $voucher->items()->create($it); }
            foreach ($rows as [$lid, $de, $ce]) {
                if ($de <= 0 && $ce <= 0) { continue; }
                AccEntry::create(['company_id' => $cid, 'voucher_id' => $voucher->id, 'ledger_id' => $lid, 'date' => $d['date'],
                                  'debit' => $de, 'credit' => $ce, 'narration' => $d['narration'] ?? null]);
            }
            return $voucher->load(['items', 'party:id,name,gstin', 'ledger:id,name', 'account:id,name']);
        });
    }

    private function taxRows(int $cid, array &$rows, string $dir, float $c, float $s, float $i, bool $credit): void
    {
        foreach (['cgst' => $c, 'sgst' => $s, 'igst' => $i] as $head => $amt) {
            if ($amt <= 0) { continue; }
            $id = $this->sys($cid, "{$dir}_{$head}");
            $rows[] = $credit ? [$id, 0, $amt] : [$id, $amt, 0];
        }
    }

    private function ledgerOf(int $cid, int $id): AccLedger
    {
        $l = AccLedger::where('company_id', $cid)->find($id);
        if (!$l) { $this->fail('ledger_id', 'That ledger does not exist.'); }
        return $l;
    }

    private function fail(string $field, string $msg): never
    {
        throw ValidationException::withMessages([$field => [$msg]]);
    }

    // ─── Balances & reports ───────────────────────────────────────────────────
    /** Signed closing balance (debit positive) for every ledger as on $asOf, from the first entry. */
    public function balances(int $cid, ?string $asOf = null, ?string $from = null): array
    {
        $q = DB::table('acc_entries')->where('company_id', $cid)
            ->when($asOf, fn ($q) => $q->where('date', '<=', $asOf))
            ->when($from, fn ($q) => $q->where('date', '>=', $from))
            ->selectRaw('ledger_id, SUM(debit) dr, SUM(credit) cr')->groupBy('ledger_id')->get()->keyBy('ledger_id');
        $out = [];
        foreach (AccLedger::where('company_id', $cid)->get() as $l) {
            $e = $q[$l->id] ?? null;
            $open = $from ? 0.0 : ($l->opening_type === 'cr' ? -1 : 1) * (float) $l->opening_balance;
            $dr = (float) ($e->dr ?? 0); $cr = (float) ($e->cr ?? 0);
            $out[$l->id] = ['ledger' => $l, 'dr' => round($dr, 2), 'cr' => round($cr, 2), 'balance' => round($open + $dr - $cr, 2)];
        }
        return $out;
    }

    public function trialBalance(int $cid, string $asOf): array
    {
        $rows = []; $td = $tc = 0.0;
        foreach ($this->balances($cid, $asOf) as $b) {
            if (abs($b['balance']) < 0.005) { continue; }
            $dr = $b['balance'] > 0 ? $b['balance'] : 0; $cr = $b['balance'] < 0 ? -$b['balance'] : 0;
            $rows[] = ['ledger_id' => $b['ledger']->id, 'name' => $b['ledger']->name, 'nature' => $b['ledger']->nature, 'group' => $b['ledger']->group, 'debit' => $dr, 'credit' => $cr];
            $td += $dr; $tc += $cr;
        }
        return ['as_on' => $asOf, 'rows' => $rows, 'total_debit' => round($td, 2), 'total_credit' => round($tc, 2)];
    }

    public function profitLoss(int $cid, string $from, string $to): array
    {
        $income = []; $expense = [];
        foreach ($this->balances($cid, $to, $from) as $b) {
            $l = $b['ledger'];
            if ($l->nature === 'income') { $v = $b['cr'] - $b['dr']; if (abs($v) > 0.004) { $income[] = ['name' => $l->name, 'group' => $l->group, 'amount' => round($v, 2)]; } }
            if ($l->nature === 'expense') { $v = $b['dr'] - $b['cr']; if (abs($v) > 0.004) { $expense[] = ['name' => $l->name, 'group' => $l->group, 'amount' => round($v, 2)]; } }
        }
        $ti = array_sum(array_column($income, 'amount')); $te = array_sum(array_column($expense, 'amount'));
        $direct = array_sum(array_map(fn ($r) => in_array($r['group'], ['purchase', 'direct_expense'], true) ? $r['amount'] : 0, $expense));
        $sales  = array_sum(array_map(fn ($r) => in_array($r['group'], ['sales', 'direct_income'], true) ? $r['amount'] : 0, $income));
        return ['from' => $from, 'to' => $to, 'income' => $income, 'expense' => $expense,
                'total_income' => round($ti, 2), 'total_expense' => round($te, 2), 'gross_profit' => round($sales - $direct, 2), 'net_profit' => round($ti - $te, 2)];
    }

    public function balanceSheet(int $cid, string $asOf): array
    {
        $assets = []; $liab = []; $profit = 0.0;
        foreach ($this->balances($cid, $asOf) as $b) {
            $l = $b['ledger']; $v = $b['balance'];
            if (abs($v) < 0.005) { continue; }
            if ($l->nature === 'income')  { $profit += -$v; continue; }
            if ($l->nature === 'expense') { $profit += -$v; continue; }
            if ($l->nature === 'asset') { $assets[] = ['name' => $l->name, 'group' => $l->group, 'amount' => round($v, 2)]; }
            else { $liab[] = ['name' => $l->name, 'group' => $l->group, 'amount' => round(-$v, 2)]; }
        }
        if (abs($profit) >= 0.005) { $liab[] = ['name' => $profit >= 0 ? 'Net Profit (retained)' : 'Net Loss (retained)', 'group' => 'profit', 'amount' => round($profit, 2)]; }
        return ['as_on' => $asOf, 'assets' => $assets, 'liabilities' => $liab,
                'total_assets' => round(array_sum(array_column($assets, 'amount')), 2), 'total_liabilities' => round(array_sum(array_column($liab, 'amount')), 2)];
    }

    public function ledgerStatement(int $cid, int $ledgerId, string $from, string $to): array
    {
        $l = $this->ledgerOf($cid, $ledgerId);
        $sign = $l->opening_type === 'cr' ? -1 : 1;
        $before = DB::table('acc_entries')->where('company_id', $cid)->where('ledger_id', $ledgerId)->where('date', '<', $from)
            ->selectRaw('COALESCE(SUM(debit),0) dr, COALESCE(SUM(credit),0) cr')->first();
        $opening = $sign * (float) $l->opening_balance + (float) $before->dr - (float) $before->cr;
        $run = $opening; $rows = []; $td = $tc = 0.0;
        $entries = AccEntry::with('voucher:id,type,number,reference_no,party_ledger_id,narration')
            ->where('company_id', $cid)->where('ledger_id', $ledgerId)->whereBetween('date', [$from, $to])->orderBy('date')->orderBy('id')->get();
        foreach ($entries as $e) {
            $run += $e->debit - $e->credit; $td += $e->debit; $tc += $e->credit;
            $rows[] = ['date' => $e->date->toDateString(), 'voucher_id' => $e->voucher_id, 'type' => $e->voucher?->type, 'number' => $e->voucher?->number,
                       'reference_no' => $e->voucher?->reference_no, 'narration' => $e->narration, 'debit' => $e->debit, 'credit' => $e->credit, 'balance' => round($run, 2)];
        }
        return ['ledger' => $l, 'from' => $from, 'to' => $to, 'opening' => round($opening, 2), 'total_debit' => round($td, 2), 'total_credit' => round($tc, 2),
                'closing' => round($run, 2), 'rows' => $rows];
    }

    /** Receivables / payables: party ledgers with their current balance. */
    public function outstanding(int $cid, string $kind): array
    {
        $group = $kind === 'payable' ? 'creditor' : 'debtor';
        $rows = [];
        foreach ($this->balances($cid) as $b) {
            if ($b['ledger']->group !== $group) { continue; }
            $v = $kind === 'payable' ? -$b['balance'] : $b['balance'];
            if (abs($v) < 0.005) { continue; }
            $rows[] = ['ledger_id' => $b['ledger']->id, 'name' => $b['ledger']->name, 'phone' => $b['ledger']->phone, 'amount' => round($v, 2)];
        }
        usort($rows, fn ($a, $b) => $b['amount'] <=> $a['amount']);
        return ['kind' => $kind, 'rows' => $rows, 'total' => round(array_sum(array_column($rows, 'amount')), 2)];
    }

    public function dashboard(int $cid, string $from, string $to): array
    {
        $pl  = $this->profitLoss($cid, $from, $to);
        $bal = $this->balances($cid);
        $cash = $bankAmt = 0.0;
        foreach ($bal as $b) {
            if ($b['ledger']->group === 'cash') { $cash += $b['balance']; }
            if ($b['ledger']->group === 'bank') { $bankAmt += $b['balance']; }
        }
        $months = DB::table('acc_entries as e')->join('acc_ledgers as l', 'l.id', '=', 'e.ledger_id')
            ->where('e.company_id', $cid)->whereBetween('e.date', [$from, $to])->whereIn('l.nature', ['income', 'expense'])
            ->selectRaw("DATE_FORMAT(e.date, '%Y-%m') m, l.nature, SUM(e.credit - e.debit) v")->groupBy('m', 'l.nature')->orderBy('m')->get();
        $chart = [];
        foreach ($months as $r) {
            $chart[$r->m] ??= ['month' => $r->m, 'income' => 0, 'expense' => 0];
            $chart[$r->m][$r->nature] = round($r->nature === 'expense' ? -$r->v : $r->v, 2);
        }
        $topExpense = collect($pl['expense'])->sortByDesc('amount')->take(6)->values();
        return [
            'from' => $from, 'to' => $to,
            'income' => $pl['total_income'], 'expense' => $pl['total_expense'], 'profit' => $pl['net_profit'],
            'cash' => round($cash, 2), 'bank' => round($bankAmt, 2),
            'receivable' => $this->outstanding($cid, 'receivable')['total'], 'payable' => $this->outstanding($cid, 'payable')['total'],
            'chart' => array_values($chart), 'top_expenses' => $topExpense,
            'gst' => $this->gst($cid, $from, $to)['summary'],
        ];
    }

    // ─── GST ──────────────────────────────────────────────────────────────────
    public function gst(int $cid, string $from, string $to): array
    {
        $v = AccVoucher::with(['party:id,name,gstin,state_code', 'items'])
            ->where('company_id', $cid)->where('is_cancelled', false)->whereBetween('date', [$from, $to])
            ->whereIn('type', ['sales', 'income', 'purchase', 'expense'])->orderBy('date')->orderBy('id')->get();
        $set = $this->settings($cid);

        $sum = fn ($rows) => [
            'taxable' => round($rows->sum('taxable'), 2), 'cgst' => round($rows->sum('cgst'), 2),
            'sgst' => round($rows->sum('sgst'), 2), 'igst' => round($rows->sum('igst'), 2),
        ];
        $out = $v->whereIn('type', ['sales', 'income'])->values();
        $in  = $set->gst_registered ? $v->whereIn('type', ['purchase', 'expense'])->values() : collect();
        $o = $sum($out); $i = $sum($in);
        $net = ['cgst' => round($o['cgst'] - $i['cgst'], 2), 'sgst' => round($o['sgst'] - $i['sgst'], 2), 'igst' => round($o['igst'] - $i['igst'], 2)];

        $row = fn ($x) => [
            'id' => $x->id, 'date' => $x->date->toDateString(), 'number' => $x->number, 'type' => $x->type, 'party' => $x->party?->name,
            'gstin' => $x->party?->gstin, 'place_of_supply' => $x->place_of_supply, 'reference_no' => $x->reference_no,
            'taxable' => $x->taxable, 'cgst' => $x->cgst, 'sgst' => $x->sgst, 'igst' => $x->igst, 'total' => $x->total,
        ];
        $b2b = $out->filter(fn ($x) => !empty($x->party?->gstin))->values()->map($row);
        $b2c = $out->filter(fn ($x) => empty($x->party?->gstin))->values()->map($row);

        $hsn = []; // outward HSN summary (GSTR-1 table 12)
        foreach ($out as $x) {
            foreach ($x->items as $it) {
                $k = ($it->hsn ?: '—') . '|' . $it->gst_rate;
                $hsn[$k] ??= ['hsn' => $it->hsn ?: '—', 'gst_rate' => $it->gst_rate, 'qty' => 0, 'taxable' => 0, 'cgst' => 0, 'sgst' => 0, 'igst' => 0];
                $hsn[$k]['qty'] += $it->qty; $hsn[$k]['taxable'] += $it->amount;
                $hsn[$k]['cgst'] += $it->cgst; $hsn[$k]['sgst'] += $it->sgst; $hsn[$k]['igst'] += $it->igst;
            }
        }
        $byRate = [];
        foreach ($out as $x) {
            foreach ($x->items as $it) {
                $byRate[(string) $it->gst_rate] ??= ['gst_rate' => $it->gst_rate, 'taxable' => 0, 'cgst' => 0, 'sgst' => 0, 'igst' => 0];
                $byRate[(string) $it->gst_rate]['taxable'] += $it->amount; $byRate[(string) $it->gst_rate]['cgst'] += $it->cgst;
                $byRate[(string) $it->gst_rate]['sgst'] += $it->sgst; $byRate[(string) $it->gst_rate]['igst'] += $it->igst;
            }
        }
        ksort($byRate, SORT_NUMERIC);

        return [
            'from' => $from, 'to' => $to, 'gstin' => $set->gstin, 'legal_name' => $set->legal_name, 'registered' => $set->gst_registered,
            'summary' => ['outward' => $o, 'itc' => $i, 'net_payable' => $net, 'total_payable' => round(array_sum($net), 2)],
            'b2b' => $b2b, 'b2c' => $b2c,
            'purchases' => $in->map($row)->values(),
            'hsn' => array_values(array_map(fn ($h) => array_map(fn ($x) => is_float($x) || is_int($x) ? round($x, 2) : $x, $h), $hsn)),
            'by_rate' => array_values($byRate),
        ];
    }

    /** Financial-year range containing $date, using the company's FY start month. */
    public function fyRange(int $cid, ?string $date = null): array
    {
        $m = $this->settings($cid)->fy_start_month ?: 4;
        $d = $date ? Carbon::parse($date) : now();
        $start = Carbon::create($d->month >= $m ? $d->year : $d->year - 1, $m, 1)->startOfDay();
        return [$start->toDateString(), $start->copy()->addYear()->subDay()->toDateString()];
    }
}
