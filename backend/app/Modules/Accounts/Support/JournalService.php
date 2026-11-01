<?php

namespace App\Modules\Accounts\Support;

use App\Modules\Accounts\Models\AccLedger;
use App\Modules\Accounts\Models\AccVoucher;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Adjusting entries made simple. Each kind is a ready-made, balanced journal, so nobody has to remember which side
 * accruals and deferrals go on. Entries flagged auto_reverse flip themselves back on the first day of the next month.
 */
class JournalService
{
    public const KINDS = [
        'accrued_expense'    => 'Expense incurred, bill not yet received (Dr Expense / Cr Accrued Expenses)',
        'prepaid_payment'    => 'Paid in advance for a future period (Dr Prepaid Expenses / Cr Cash-Bank)',
        'prepaid_amortize'   => 'Use up part of a prepaid expense (Dr Expense / Cr Prepaid Expenses)',
        'unearned_receive'   => 'Money received for services not yet delivered (Dr Cash-Bank / Cr Unearned Revenue)',
        'unearned_recognize' => 'Service delivered — recognise the revenue (Dr Unearned Revenue / Cr Revenue)',
    ];

    public function __construct(private readonly AccountsService $svc) {}

    private function fail(string $field, string $msg): never
    {
        throw ValidationException::withMessages([$field => [$msg]]);
    }

    private function ledger(int $cid, $id, string $field, array $natures, string $label): AccLedger
    {
        $l = $id ? AccLedger::where('company_id', $cid)->find($id) : null;
        if (!$l || !in_array($l->nature, $natures, true)) { $this->fail($field, "Select a valid {$label}."); }
        return $l;
    }

    public function adjusting(int $cid, ?int $userId, array $d): AccVoucher
    {
        $this->svc->seedDefaults($cid);
        $kind = $d['kind'] ?? '';
        if (!isset(self::KINDS[$kind])) { $this->fail('kind', 'Unknown adjusting entry.'); }
        $amt = round((float) ($d['amount'] ?? 0), 2);
        if ($amt <= 0) { $this->fail('amount', 'Enter an amount greater than zero.'); }
        $date = Carbon::parse($d['date']);
        $note = trim((string) ($d['narration'] ?? ''));

        switch ($kind) {
            case 'accrued_expense':
                $e = $this->ledger($cid, $d['ledger_id'] ?? null, 'ledger_id', ['expense'], 'expense account');
                $rows = [[$e->id, $amt, 0], [$this->svc->sys($cid, 'accrued_expenses'), 0, $amt]];
                $text = "Accrued {$e->name}";
                break;
            case 'prepaid_payment':
                $from = $this->ledger($cid, $d['account_ledger_id'] ?? null, 'account_ledger_id', ['asset', 'liability'], 'cash/bank/supplier account');
                $rows = [[$this->svc->sys($cid, 'prepaid'), $amt, 0], [$from->id, 0, $amt]];
                $text = 'Prepaid expense paid from ' . $from->name;
                break;
            case 'prepaid_amortize':
                $e = $this->ledger($cid, $d['ledger_id'] ?? null, 'ledger_id', ['expense'], 'expense account');
                $left = $this->svc->ledgerBalance($cid, $this->svc->sys($cid, 'prepaid'));
                if ($amt > $left + 0.004) { $this->fail('amount', 'Only ' . number_format(max($left, 0), 2) . ' is left in Prepaid Expenses.'); }
                $rows = [[$e->id, $amt, 0], [$this->svc->sys($cid, 'prepaid'), 0, $amt]];
                $text = "Prepaid expense used: {$e->name}";
                break;
            case 'unearned_receive':
                $to = $this->ledger($cid, $d['account_ledger_id'] ?? null, 'account_ledger_id', ['asset'], 'cash/bank account');
                $rows = [[$to->id, $amt, 0], [$this->svc->sys($cid, 'unearned_revenue'), 0, $amt]];
                $text = 'Unearned revenue received into ' . $to->name;
                break;
            default: // unearned_recognize
                $r = $this->ledger($cid, $d['ledger_id'] ?? null, 'ledger_id', ['income'], 'revenue account');
                $left = -$this->svc->ledgerBalance($cid, $this->svc->sys($cid, 'unearned_revenue'));
                if ($amt > $left + 0.004) { $this->fail('amount', 'Only ' . number_format(max($left, 0), 2) . ' of unearned revenue is left to recognise.'); }
                $rows = [[$this->svc->sys($cid, 'unearned_revenue'), $amt, 0], [$r->id, 0, $amt]];
                $text = "Revenue recognised: {$r->name}";
        }

        $reverse = !empty($d['auto_reverse']);
        if ($reverse && $kind !== 'accrued_expense') { $this->fail('auto_reverse', 'Only accruals reverse automatically.'); }
        return $this->svc->saveVoucher($cid, $userId, [
            'type' => 'journal', 'date' => $date->toDateString(), 'sub_type' => 'adjusting',
            'reverse_on' => $reverse ? $date->copy()->addDay()->toDateString() : null,
            'narration' => $note !== '' ? $note : $text,
            'entries' => array_map(fn ($r) => ['ledger_id' => $r[0], 'debit' => $r[1], 'credit' => $r[2]], $rows),
        ]);
    }

    // ─── Auto-reversal ────────────────────────────────────────────────────────
    /** Post every reversal that has come due and has not been posted yet. Returns how many were created. */
    public function postDueReversals(int $cid, ?string $asOf = null): int
    {
        $asOf ??= now()->toDateString();
        $due = AccVoucher::with('entries')->where('company_id', $cid)->where('is_cancelled', false)->whereNotNull('reverse_on')->where('reverse_on', '<=', $asOf)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('acc_vouchers as r')->whereColumn('r.reverses_voucher_id', 'acc_vouchers.id'))->orderBy('reverse_on')->get();
        $lock = $this->svc->settings($cid)->locked_until;
        $n = 0;
        foreach ($due as $v) {
            if ($lock && $v->reverse_on->lte($lock)) { continue; } // that period is closed — leave it for the accountant to deal with
            $this->svc->saveVoucher($cid, null, [
                'type' => 'journal', 'date' => $v->reverse_on->toDateString(), 'sub_type' => 'adjusting', 'source_type' => 'reversal', 'source_id' => $v->id,
                'reverses_voucher_id' => $v->id, 'narration' => "Auto-reversal of {$v->number}",
                'entries' => $v->entries->map(fn ($e) => ['ledger_id' => $e->ledger_id, 'debit' => $e->credit, 'credit' => $e->debit])->all(),
            ]);
            $n++;
        }
        return $n;
    }

    /** Stop a pending reversal, or remove one already posted. */
    public function cancelReversal(int $cid, int $voucherId): void
    {
        $v = AccVoucher::where('company_id', $cid)->findOrFail($voucherId);
        if (!$v->reverse_on) { $this->fail('voucher', 'This entry is not set to reverse.'); }
        DB::transaction(function () use ($cid, $v) {
            $rev = AccVoucher::where('company_id', $cid)->where('reverses_voucher_id', $v->id)->first();
            if ($rev) { $this->svc->deleteVoucher($cid, $rev); }
            $v->update(['reverse_on' => null]);
        });
    }

    /** Adjusting entries with their reversal status, for the screen. */
    public function list(int $cid, string $from, string $to): array
    {
        $this->postDueReversals($cid);
        $rows = AccVoucher::where('company_id', $cid)->where('type', 'journal')->where('sub_type', 'adjusting')->whereBetween('date', [$from, $to])->orderByDesc('date')->orderByDesc('id')->get();
        $reversedBy = AccVoucher::where('company_id', $cid)->whereIn('reverses_voucher_id', $rows->pluck('id'))->pluck('number', 'reverses_voucher_id');
        return ['rows' => $rows->map(fn ($v) => ['id' => $v->id, 'number' => $v->number, 'date' => $v->date->toDateString(), 'total' => $v->total, 'narration' => $v->narration,
            'source_type' => $v->source_type, 'reverse_on' => $v->reverse_on?->toDateString(), 'reversed_by' => $reversedBy[$v->id] ?? null,
            'reversal_pending' => $v->reverse_on && !isset($reversedBy[$v->id])])->values(), 'kinds' => self::KINDS];
    }
}
