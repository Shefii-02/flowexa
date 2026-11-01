<?php

namespace App\Modules\Accounts\Support;

use App\Modules\Accounts\Models\AccLedger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Imprest petty cash: a fixed float is topped up from the bank, small expenses are paid out of it, and when it runs low it
 * is replenished back to the float. Petty cash can never go below zero (enforced in AccountsService::saveVoucher).
 */
class PettyCashService
{
    public function __construct(private readonly AccountsService $svc) {}

    private function fail(string $field, string $msg): never
    {
        throw ValidationException::withMessages([$field => [$msg]]);
    }

    public function summary(int $cid, string $from, string $to): array
    {
        $this->svc->seedDefaults($cid);
        $petty = $this->svc->sys($cid, 'petty_cash');
        $limit = (float) $this->svc->settings($cid)->petty_cash_limit;
        $balance = $this->svc->ledgerBalance($cid, $petty);

        $stmt = $this->svc->ledgerStatement($cid, $petty, $from, $to);
        $spentBy = DB::table('acc_entries as p')->join('acc_entries as x', 'x.voucher_id', '=', 'p.voucher_id')->join('acc_ledgers as l', 'l.id', '=', 'x.ledger_id')
            ->where('p.company_id', $cid)->where('p.ledger_id', $petty)->where('p.credit', '>', 0)->where('x.debit', '>', 0)->where('x.ledger_id', '!=', $petty)
            ->whereBetween('p.date', [$from, $to])->selectRaw('l.name name, SUM(x.debit) amount')->groupBy('l.name')->orderByDesc('amount')->get();
        $topups = round(collect($stmt['rows'])->where('type', 'contra')->sum('debit'), 2);
        return ['ledger_id' => $petty, 'float_limit' => $limit, 'balance' => $balance, 'available' => $balance,
                'replenish_needed' => $limit > 0 ? max(0.0, round($limit - $balance, 2)) : 0.0, 'low' => $limit > 0 && $balance <= $limit * 0.2,
                'from' => $from, 'to' => $to, 'opening' => $stmt['opening'], 'topups' => $topups, 'spent' => round($spentBy->sum('amount'), 2), 'closing' => $stmt['closing'],
                'spent_by_category' => $spentBy, 'rows' => $stmt['rows']];
    }

    /** Move money from bank/cash into petty cash. Without an amount it tops up to the float. */
    public function topUp(int $cid, ?int $userId, array $d)
    {
        $this->svc->seedDefaults($cid);
        $petty = $this->svc->sys($cid, 'petty_cash');
        $from = AccLedger::where('company_id', $cid)->whereIn('group', ['bank', 'cash'])->where('id', '!=', $petty)->find($d['from_ledger_id'] ?? 0);
        if (!$from) { $this->fail('from_ledger_id', 'Choose the bank or cash account to take the money from.'); }
        $limit = (float) $this->svc->settings($cid)->petty_cash_limit;
        $balance = $this->svc->ledgerBalance($cid, $petty);
        $amount = isset($d['amount']) && (float) $d['amount'] > 0 ? round((float) $d['amount'], 2) : ($limit > 0 ? round($limit - $balance, 2) : 0.0);
        if ($amount <= 0) { $this->fail('amount', $limit > 0 ? 'Petty cash is already at its float.' : 'Enter the amount to add.'); }
        if ($limit > 0 && $balance + $amount > $limit + 0.004) {
            $this->fail('amount', sprintf('That would take petty cash to %s, over its float of %s. The most you can add is %s.', number_format($balance + $amount, 2), number_format($limit, 2), number_format(max(0, $limit - $balance), 2)));
        }
        return $this->svc->saveVoucher($cid, $userId, ['type' => 'contra', 'date' => $d['date'], 'ledger_id' => $petty, 'account_ledger_id' => $from->id,
            'amount' => $amount, 'narration' => $d['narration'] ?? 'Petty cash top-up']);
    }

    /** Pay a small expense out of petty cash. */
    public function spend(int $cid, ?int $userId, array $d)
    {
        $this->svc->seedDefaults($cid);
        return $this->svc->saveVoucher($cid, $userId, ['type' => 'expense', 'date' => $d['date'], 'ledger_id' => $d['ledger_id'] ?? null,
            'account_ledger_id' => $this->svc->sys($cid, 'petty_cash'), 'amount' => $d['amount'] ?? 0, 'gst_rate' => $d['gst_rate'] ?? 0,
            'description' => $d['description'] ?? null, 'reference_no' => $d['reference_no'] ?? null, 'narration' => $d['narration'] ?? ($d['description'] ?? null)]);
    }
}
