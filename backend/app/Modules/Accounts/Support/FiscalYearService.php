<?php

namespace App\Modules\Accounts\Support;

use App\Modules\Accounts\Models\AccFiscalYear;
use App\Modules\Accounts\Models\AccVoucher;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Fiscal years are closed strictly in order. Closing moves the year's profit or loss into Retained Earnings with a
 * closing journal and locks the books up to the year end; only the most recent closed year can be reopened.
 */
class FiscalYearService
{
    public function __construct(private readonly AccountsService $svc) {}

    public function name(Carbon $start, int $startMonth): string
    {
        return $startMonth === 1 ? 'FY ' . $start->year : sprintf('FY %d-%02d', $start->year, ($start->year + 1) % 100);
    }

    /** One row per financial year from the first transaction (or this year) up to the current one. */
    public function ensure(int $cid): void
    {
        $month = $this->svc->settings($cid)->fy_start_month ?: 4;
        $first = AccVoucher::where('company_id', $cid)->min('date');
        $last  = AccVoucher::where('company_id', $cid)->max('date');
        [$s0] = $this->svc->fyRange($cid, $first ?: null);
        [$sN] = $this->svc->fyRange($cid, max($last ?: '1970-01-01', now()->toDateString()));
        for ($s = Carbon::parse($s0); $s->lte(Carbon::parse($sN)); $s->addYear()) {
            AccFiscalYear::firstOrCreate(['company_id' => $cid, 'start_date' => $s->toDateString()], [
                'name' => $this->name($s, $month), 'end_date' => $s->copy()->addYear()->subDay()->toDateString(), 'status' => 'open']);
        }
    }

    public function list(int $cid): array
    {
        $this->svc->seedDefaults($cid);
        $this->ensure($cid);
        $set = $this->svc->settings($cid);
        $rows = AccFiscalYear::where('company_id', $cid)->orderByDesc('start_date')->get()->map(function ($fy) {
            return $fy->toArray() + ['current' => $fy->start_date->lte(now()) && $fy->end_date->gte(now()->startOfDay())];
        });
        return ['years' => $rows, 'locked_until' => $set->locked_until?->toDateString()];
    }

    private function fail(string $msg): never
    {
        throw ValidationException::withMessages(['fiscal_year' => [$msg]]);
    }

    private function find(int $cid, int $id): AccFiscalYear
    {
        $this->ensure($cid);
        return AccFiscalYear::where('company_id', $cid)->findOrFail($id);
    }

    /** What closing would do, plus anything that blocks it. */
    public function preview(int $cid, int $id): array
    {
        $this->svc->seedDefaults($cid);
        $fy = $this->find($cid, $id);
        $from = $fy->start_date->toDateString(); $to = $fy->end_date->toDateString();
        $pl = $this->svc->profitLoss($cid, $from, $to);
        $tb = $this->svc->trialBalance($cid, $to);

        $blockers = [];
        if ($fy->status === 'closed') { $blockers[] = 'This year is already closed.'; }
        $earlier = AccFiscalYear::where('company_id', $cid)->where('status', 'open')->where('start_date', '<', $from)->orderBy('start_date')->first();
        if ($earlier) { $blockers[] = "Close {$earlier->name} first — years are closed in order."; }
        if ($fy->end_date->gte(now()->startOfDay())) { $blockers[] = 'The year has not ended yet (ends ' . $fy->end_date->format('d M Y') . ').'; }

        $warnings = [];
        if (abs($tb['total_debit'] - $tb['total_credit']) >= 0.01) {
            $warnings[] = 'The trial balance does not tally (difference ' . number_format($tb['total_debit'] - $tb['total_credit'], 2) . '). Check ledger opening balances.';
        }
        $pending = DB::table('acc_entries')->where('company_id', $cid)->whereBetween('date', [$from, $to])->whereNull('reconciled_on')
            ->whereIn('ledger_id', DB::table('acc_ledgers')->where('company_id', $cid)->where('group', 'bank')->select('id'))->count();
        if ($pending > 0) { $warnings[] = "$pending bank entries in this year are not reconciled yet."; }

        $unposted = app(AssetService::class)->previewRun($cid, $to);
        if ($unposted['months'] && collect($unposted['months'])->contains(fn ($m) => $m['period_end'] <= $to)) {
            $blockers[] = 'Depreciation has not been posted up to ' . $fy->end_date->format('d M Y') . ' (' . number_format($unposted['total'], 2) . ' pending) — run it from Fixed Assets first.';
        }
        $due = \App\Modules\Accounts\Models\AccVoucher::where('company_id', $cid)->whereNotNull('reverse_on')->where('reverse_on', '<=', $to)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('acc_vouchers as r')->whereColumn('r.reverses_voucher_id', 'acc_vouchers.id'))->count();
        if ($due > 0) { $warnings[] = "$due auto-reversing accrual(s) are due on or before year end and have not been reversed yet."; }

        return ['year' => $fy, 'income' => $pl['total_income'], 'expense' => $pl['total_expense'], 'net_profit' => $pl['net_profit'],
                'rows' => ['income' => $pl['income'], 'expense' => $pl['expense']],
                'blockers' => $blockers, 'warnings' => $warnings, 'can_close' => !$blockers];
    }

    public function close(int $cid, ?int $userId, int $id): AccFiscalYear
    {
        $p = $this->preview($cid, $id);
        if ($p['blockers']) { $this->fail($p['blockers'][0]); }
        $fy = $p['year'];

        return DB::transaction(function () use ($cid, $userId, $fy, $p) {
            $entries = [];
            foreach ($p['rows']['income'] as $r)  { $entries[] = $r['amount'] >= 0 ? ['ledger_id' => $r['ledger_id'], 'debit' => $r['amount']] : ['ledger_id' => $r['ledger_id'], 'credit' => -$r['amount']]; }
            foreach ($p['rows']['expense'] as $r) { $entries[] = $r['amount'] >= 0 ? ['ledger_id' => $r['ledger_id'], 'credit' => $r['amount']] : ['ledger_id' => $r['ledger_id'], 'debit' => -$r['amount']]; }
            $net = $p['net_profit'];
            $voucherId = null;
            if ($entries) {
                $re = $this->svc->sys($cid, 'retained');
                $entries[] = $net >= 0 ? ['ledger_id' => $re, 'credit' => $net] : ['ledger_id' => $re, 'debit' => -$net];
                $entries = array_values(array_filter($entries, fn ($e) => ($e['debit'] ?? 0) > 0 || ($e['credit'] ?? 0) > 0));
                $v = $this->svc->saveVoucher($cid, $userId, [
                    'type' => 'journal', 'date' => $fy->end_date->toDateString(), 'sub_type' => 'closing',
                    'source_type' => 'fiscal_year', 'source_id' => $fy->id, 'entries' => $entries,
                    'narration' => "Year-end closing {$fy->name}: profit & loss transferred to Retained Earnings",
                ]);
                $voucherId = $v->id;
            }
            $fy->update(['status' => 'closed', 'closed_at' => now(), 'closed_by' => $userId, 'closing_voucher_id' => $voucherId, 'net_profit' => $net]);
            $this->svc->settings($cid)->update(['locked_until' => $fy->end_date->toDateString()]);
            return $fy->fresh();
        });
    }

    public function reopen(int $cid, int $id): AccFiscalYear
    {
        $fy = $this->find($cid, $id);
        if ($fy->status !== 'closed') { $this->fail('This year is not closed.'); }
        $later = AccFiscalYear::where('company_id', $cid)->where('status', 'closed')->where('start_date', '>', $fy->start_date->toDateString())->first();
        if ($later) { $this->fail("Reopen {$later->name} first — only the most recent closed year can be reopened."); }

        return DB::transaction(function () use ($cid, $fy) {
            $prev = AccFiscalYear::where('company_id', $cid)->where('status', 'closed')->where('start_date', '<', $fy->start_date->toDateString())->orderByDesc('start_date')->first();
            $this->svc->settings($cid)->update(['locked_until' => $prev?->end_date->toDateString()]); // unlock first so the closing journal can go
            if ($fy->closing_voucher_id && ($v = AccVoucher::where('company_id', $cid)->find($fy->closing_voucher_id))) { $this->svc->deleteVoucher($cid, $v); }
            $fy->update(['status' => 'open', 'closed_at' => null, 'closed_by' => null, 'closing_voucher_id' => null, 'net_profit' => null]);
            return $fy->fresh();
        });
    }
}
