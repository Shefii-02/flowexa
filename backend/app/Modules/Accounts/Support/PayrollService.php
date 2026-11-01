<?php

namespace App\Modules\Accounts\Support;

use App\Modules\Accounts\Models\AccEmployee;
use App\Modules\Accounts\Models\AccLedger;
use App\Modules\Accounts\Models\AccPayrollLine;
use App\Modules\Accounts\Models\AccPayrollRun;
use App\Modules\Accounts\Models\AccVoucher;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Monthly payroll, in strict order:  create run → review lines → post (accrues salary + leave/ticket/end-of-service provisions)
 * → pay. Months are run one after another with no gaps, and only the latest run can be taken back.
 * Final settlement pays out what the provisions hold and trues the difference up through expense.
 */
class PayrollService
{
    public function __construct(private readonly AccountsService $svc) {}

    private function fail(string $field, string $msg): never
    {
        throw ValidationException::withMessages([$field => [$msg]]);
    }

    // ─── Employees ────────────────────────────────────────────────────────────
    public function employee(int $cid, int $id): AccEmployee
    {
        return AccEmployee::where('company_id', $cid)->findOrFail($id);
    }

    public function saveEmployee(int $cid, array $d, ?AccEmployee $e = null): AccEmployee
    {
        if (!empty($d['left_on']) && !empty($d['joined_on']) && Carbon::parse($d['left_on'])->lt(Carbon::parse($d['joined_on']))) { $this->fail('left_on', 'The last working day is before the joining date.'); }
        if ($e && $e->status === 'left' && $e->settled_on) { $this->fail('employee', 'This employee has been settled and can no longer be edited.'); }
        if ($e && AccPayrollLine::where('employee_id', $e->id)->exists()) {
            // pay terms of past months are history — only change them going forward via a new run, not by rewriting joining date
            if (isset($d['joined_on']) && Carbon::parse($d['joined_on'])->toDateString() !== $e->joined_on->toDateString()) { $this->fail('joined_on', 'The joining date cannot change once payroll has been run for this employee.'); }
        }
        if ($e) { $e->update($d); return $e->fresh(); }
        return AccEmployee::create($d + ['company_id' => $cid]);
    }

    public function deleteEmployee(int $cid, int $id): void
    {
        $e = $this->employee($cid, $id);
        if (AccPayrollLine::where('employee_id', $id)->exists()) { $this->fail('employee', 'This employee is on payroll history — mark them as left instead.'); }
        $e->delete();
    }

    /** Provisions accrued so far (posted and paid runs only). */
    public function accrued(int $employeeId): array
    {
        $q = AccPayrollLine::where('employee_id', $employeeId)->whereIn('run_id', AccPayrollRun::whereIn('status', ['posted', 'paid'])->select('id'));
        return ['leave' => round((float) (clone $q)->sum('leave_accrual'), 2), 'ticket' => round((float) (clone $q)->sum('ticket_accrual'), 2), 'eos' => round((float) (clone $q)->sum('eos_accrual'), 2)];
    }

    public function employees(int $cid): array
    {
        $rows = AccEmployee::where('company_id', $cid)->orderBy('status')->orderBy('name')->get()->map(fn ($e) => $e->toArray() + ['accrued' => $this->accrued($e->id), 'gross_monthly' => round($e->basic + $e->allowance, 2)]);
        $active = $rows->where('status', 'active');
        return ['rows' => $rows->values(), 'active' => $active->count(), 'monthly_gross' => round($active->sum('gross_monthly'), 2),
                'provisions_held' => round($active->sum(fn ($r) => array_sum($r['accrued'])), 2)];
    }

    // ─── Runs ─────────────────────────────────────────────────────────────────
    private function bounds(string $period): array
    {
        $s = Carbon::createFromFormat('Y-m-d', $period . '-01')->startOfDay();
        return [$s, $s->copy()->endOfMonth()->startOfDay()];
    }

    /** One employee's figures for a month. */
    public function calc(AccEmployee $e, string $period, float $commission = 0, float $unpaidDays = 0, float $other = 0): ?array
    {
        [$start, $end] = $this->bounds($period);
        $from = $e->joined_on->gt($start) ? $e->joined_on : $start;
        $to = $e->left_on && $e->left_on->lt($end) ? $e->left_on : $end;
        if ($from->gt($to)) { return null; }
        $factor = ($from->diffInDays($to) + 1) / $start->daysInMonth;
        $basic = round($e->basic * $factor, 2); $allow = round($e->allowance * $factor, 2);
        $unpaid = min(round(($e->basic + $e->allowance) / 30 * $unpaidDays, 2), round($basic + $allow, 2));
        $net = round($basic + $allow + $commission - $unpaid - $other, 2);
        return ['basic' => $basic, 'allowance' => $allow, 'commission' => round($commission, 2), 'unpaid_days' => $unpaidDays, 'unpaid_deduction' => $unpaid, 'other_deductions' => round($other, 2), 'net' => $net,
                'leave_accrual' => round($e->basic / 30 * $e->leave_days_per_year / 12 * $factor, 2), 'ticket_accrual' => round($e->annual_ticket / 12 * $factor, 2),
                'eos_accrual' => round($e->basic / 30 * $e->eos_days_per_year / 12 * $factor, 2)];
    }

    public function createRun(int $cid, ?int $userId, string $period): AccPayrollRun
    {
        $this->svc->seedDefaults($cid);
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) { $this->fail('period', 'Use the format YYYY-MM.'); }
        [$start] = $this->bounds($period);
        if ($start->gt(now())) { $this->fail('period', 'Payroll cannot be run for a future month.'); }
        $latest = AccPayrollRun::where('company_id', $cid)->orderByDesc('period')->first();
        if ($latest) {
            $next = Carbon::createFromFormat('Y-m-d', $latest->period . '-01')->addMonth()->format('Y-m');
            if ($period !== $next) { $this->fail('period', $period <= $latest->period ? "Payroll for {$period} already exists or is behind the latest run ({$latest->period})." : "Run payroll month by month — the next one is {$next}."); }
            if ($latest->status === 'draft') { $this->fail('period', "Post the {$latest->period} payroll before starting {$period}."); }
        }
        return DB::transaction(function () use ($cid, $userId, $period) {
            $run = AccPayrollRun::create(['company_id' => $cid, 'period' => $period, 'status' => 'draft', 'created_by' => $userId]);
            $this->fillLines($run);
            return $this->show($cid, $run->id);
        });
    }

    /** (Re)build the lines for a draft run, keeping what was typed in for employees who are still in it. */
    public function fillLines(AccPayrollRun $run): void
    {
        $keep = AccPayrollLine::where('run_id', $run->id)->get()->keyBy('employee_id');
        AccPayrollLine::where('run_id', $run->id)->delete();
        foreach (AccEmployee::where('company_id', $run->company_id)->whereNull('settled_on')->orderBy('name')->get() as $e) {
            $k = $keep[$e->id] ?? null;
            $c = $this->calc($e, $run->period, $k->commission ?? 0, $k->unpaid_days ?? 0, $k->other_deductions ?? 0);
            if (!$c) { continue; }
            AccPayrollLine::create(['run_id' => $run->id, 'employee_id' => $e->id, 'notes' => $k->notes ?? null] + $c);
        }
        $this->totals($run);
    }

    private function totals(AccPayrollRun $run): void
    {
        $l = AccPayrollLine::where('run_id', $run->id)->get();
        $run->update(['total_gross' => round($l->sum(fn ($x) => $x->basic + $x->allowance + $x->commission), 2), 'total_deductions' => round($l->sum(fn ($x) => $x->unpaid_deduction + $x->other_deductions), 2),
                      'total_net' => round($l->sum('net'), 2), 'total_provisions' => round($l->sum(fn ($x) => $x->leave_accrual + $x->ticket_accrual + $x->eos_accrual), 2)]);
    }

    public function show(int $cid, int $id): AccPayrollRun
    {
        return AccPayrollRun::with(['lines.employee:id,name,code,designation'])->where('company_id', $cid)->findOrFail($id);
    }

    public function runs(int $cid): array
    {
        return ['rows' => AccPayrollRun::where('company_id', $cid)->orderByDesc('period')->get(),
                'next_period' => ($l = AccPayrollRun::where('company_id', $cid)->orderByDesc('period')->first())
                    ? Carbon::createFromFormat('Y-m-d', $l->period . '-01')->addMonth()->format('Y-m') : now()->format('Y-m')];
    }

    private function draft(int $cid, int $id): AccPayrollRun
    {
        $r = AccPayrollRun::where('company_id', $cid)->findOrFail($id);
        if ($r->status !== 'draft') { $this->fail('status', "The {$r->period} payroll is already {$r->status} — take it back first to change it."); }
        return $r;
    }

    public function updateLine(int $cid, int $runId, int $lineId, array $d): AccPayrollRun
    {
        $run = $this->draft($cid, $runId);
        $line = AccPayrollLine::where('run_id', $run->id)->findOrFail($lineId);
        $e = $this->employee($cid, $line->employee_id);
        $c = $this->calc($e, $run->period, (float) ($d['commission'] ?? $line->commission), (float) ($d['unpaid_days'] ?? $line->unpaid_days), (float) ($d['other_deductions'] ?? $line->other_deductions));
        if ($c['net'] < 0) { $this->fail('other_deductions', 'Deductions are more than the pay for the month.'); }
        $line->update($c + ['notes' => $d['notes'] ?? $line->notes]);
        $this->totals($run);
        return $this->show($cid, $run->id);
    }

    public function post(int $cid, ?int $userId, int $id): AccPayrollRun
    {
        $run = $this->draft($cid, $id);
        $this->fillLines($run);   // pick up any pay-term change since the draft was opened
        $lines = AccPayrollLine::where('run_id', $run->id)->get();
        if ($lines->isEmpty()) { $this->fail('lines', 'There are no employees to pay in this month.'); }
        if ($lines->contains(fn ($l) => $l->net < 0)) { $this->fail('lines', 'An employee has negative net pay — correct the deductions.'); }
        $earlier = AccPayrollRun::where('company_id', $cid)->where('period', '<', $run->period)->where('status', 'draft')->first();
        if ($earlier) { $this->fail('status', "Post {$earlier->period} first."); }

        $sum = fn (string $f) => round($lines->sum($f), 2);
        $wages = round($lines->sum(fn ($l) => $l->basic + $l->allowance - $l->unpaid_deduction), 2);
        $dr = [['salaries', $wages], ['commission', $sum('commission')], ['leave_expense', $sum('leave_accrual')], ['ticket_expense', $sum('ticket_accrual')], ['eos_expense', $sum('eos_accrual')]];
        $cr = [['salary_payable', $sum('net')], ['payroll_deductions', $sum('other_deductions')], ['leave_provision', $sum('leave_accrual')], ['ticket_provision', $sum('ticket_accrual')], ['eos_provision', $sum('eos_accrual')]];
        $entries = [];
        foreach ($dr as [$k, $amt]) { if ($amt > 0) { $entries[] = ['ledger_id' => $this->svc->sys($cid, $k), 'debit' => $amt]; } }
        foreach ($cr as [$k, $amt]) { if ($amt > 0) { $entries[] = ['ledger_id' => $this->svc->sys($cid, $k), 'credit' => $amt]; } }

        return DB::transaction(function () use ($cid, $userId, $run, $entries) {
            [, $end] = $this->bounds($run->period);
            $v = $this->svc->saveVoucher($cid, $userId, ['type' => 'journal', 'date' => $end->toDateString(), 'sub_type' => 'adjusting', 'source_type' => 'payroll', 'source_id' => $run->id,
                'entries' => $entries, 'narration' => 'Payroll for ' . $end->format('F Y')]);
            $run->update(['status' => 'posted', 'voucher_id' => $v->id]);
            return $this->show($cid, $run->id);
        });
    }

    private function latestRun(int $cid, AccPayrollRun $r): void
    {
        if (AccPayrollRun::where('company_id', $cid)->where('period', '>', $r->period)->exists()) { $this->fail('status', 'Only the most recent payroll can be taken back.'); }
    }

    public function unpost(int $cid, int $id): AccPayrollRun
    {
        $run = AccPayrollRun::where('company_id', $cid)->findOrFail($id);
        if ($run->status !== 'posted') { $this->fail('status', $run->status === 'paid' ? 'This payroll has been paid — reverse the payment first.' : 'This payroll is not posted.'); }
        $this->latestRun($cid, $run);
        if (AccEmployee::where('company_id', $cid)->whereNotNull('settled_on')->where('settled_on', '>=', $this->bounds($run->period)[0]->toDateString())->exists()) {
            $this->fail('status', 'An employee was settled after this month — their settlement relies on this payroll.');
        }
        return DB::transaction(function () use ($cid, $run) {
            if ($v = AccVoucher::where('company_id', $cid)->find($run->voucher_id)) { $this->svc->deleteVoucher($cid, $v); }
            $run->update(['status' => 'draft', 'voucher_id' => null]);
            return $this->show($cid, $run->id);
        });
    }

    public function pay(int $cid, ?int $userId, int $id, int $accountLedgerId, ?string $date = null): AccPayrollRun
    {
        $run = AccPayrollRun::where('company_id', $cid)->findOrFail($id);
        if ($run->status !== 'posted') { $this->fail('status', $run->status === 'paid' ? 'Salaries for this month are already paid.' : 'Post the payroll before paying it.'); }
        $acct = AccLedger::where('company_id', $cid)->whereIn('group', ['bank', 'cash'])->find($accountLedgerId);
        if (!$acct) { $this->fail('account_ledger_id', 'Choose the cash or bank account the salaries are paid from.'); }
        $date = $date ?: now()->toDateString();
        if (Carbon::parse($date)->lt($this->bounds($run->period)[0])) { $this->fail('date', 'Salaries cannot be paid before the month starts.'); }
        if ($run->total_net <= 0) { $this->fail('status', 'There is nothing to pay.'); }

        return DB::transaction(function () use ($cid, $userId, $run, $acct, $date) {
            $v = $this->svc->saveVoucher($cid, $userId, ['type' => 'journal', 'date' => $date, 'sub_type' => 'adjusting', 'source_type' => 'payroll_pay', 'source_id' => $run->id,
                'entries' => [['ledger_id' => $this->svc->sys($cid, 'salary_payable'), 'debit' => $run->total_net], ['ledger_id' => $acct->id, 'credit' => $run->total_net]],
                'narration' => 'Salaries paid for ' . $this->bounds($run->period)[0]->format('F Y')]);
            $run->update(['status' => 'paid', 'payment_voucher_id' => $v->id, 'paid_on' => $date]);
            return $this->show($cid, $run->id);
        });
    }

    public function unpay(int $cid, int $id): AccPayrollRun
    {
        $run = AccPayrollRun::where('company_id', $cid)->findOrFail($id);
        if ($run->status !== 'paid') { $this->fail('status', 'This payroll has not been paid.'); }
        return DB::transaction(function () use ($cid, $run) {
            if ($v = AccVoucher::where('company_id', $cid)->find($run->payment_voucher_id)) { $this->svc->deleteVoucher($cid, $v); }
            $run->update(['status' => 'posted', 'payment_voucher_id' => null, 'paid_on' => null]);
            return $this->show($cid, $run->id);
        });
    }

    public function deleteRun(int $cid, int $id): void
    {
        $run = $this->draft($cid, $id);
        $this->latestRun($cid, $run);
        DB::transaction(function () use ($run) { AccPayrollLine::where('run_id', $run->id)->delete(); $run->delete(); });
    }

    // ─── End-of-service settlement ────────────────────────────────────────────
    public function quote(int $cid, int $employeeId, ?string $date = null): array
    {
        $e = $this->employee($cid, $employeeId);
        $d = Carbon::parse($e->left_on ?? $date ?? now()->toDateString())->startOfDay();
        $years = max(0, $e->joined_on->diffInDays($d) / 365);
        $held = $this->accrued($e->id);
        $held['ticket'] = round($held['ticket'] - $e->ticket_used, 2);

        $eos = $years < 1 ? 0.0 : round($e->basic / 30 * $e->eos_days_per_year * $years, 2);   // not payable before one full year of service
        $leaveDays = max(0, round($e->leave_days_per_year * $years - $e->leave_taken_days, 2));
        $due = ['eos' => $eos, 'leave' => round($e->basic / 30 * $leaveDays, 2), 'ticket' => max(0.0, $held['ticket'])];

        $problems = [];
        if ($e->settled_on) { $problems[] = 'Already settled.'; }
        if ($d->lt($e->joined_on)) { $problems[] = 'The last working day is before the joining date.'; }
        if (AccPayrollLine::where('employee_id', $e->id)->whereIn('run_id', AccPayrollRun::where('status', 'draft')->select('id'))->exists()) { $problems[] = 'Post or remove the draft payroll that includes this employee first.'; }
        $period = $d->format('Y-m');
        $finalRun = AccPayrollRun::where('company_id', $cid)->where('period', $period)->first();
        if (!$finalRun || $finalRun->status === 'draft') { $problems[] = "Post the payroll for {$period} first so the final month's salary and accruals are included."; }

        return ['employee' => $e, 'date' => $d->toDateString(), 'years_of_service' => round($years, 2), 'leave_days_balance' => $leaveDays,
                'due' => $due, 'provision_held' => $held, 'total_due' => round(array_sum($due), 2), 'problems' => $problems, 'can_settle' => !$problems];
    }

    public function settle(int $cid, ?int $userId, int $employeeId, array $d): AccEmployee
    {
        $q = $this->quote($cid, $employeeId, $d['date'] ?? null);
        if ($q['problems']) { $this->fail('employee', $q['problems'][0]); }
        $e = $q['employee']; $total = $q['total_due'];
        $acct = null;
        if ($total > 0) {
            $acct = AccLedger::where('company_id', $cid)->whereIn('group', ['bank', 'cash'])->find($d['account_ledger_id'] ?? 0);
            if (!$acct) { $this->fail('account_ledger_id', 'Choose the cash or bank account the settlement is paid from.'); }
        }
        return DB::transaction(function () use ($cid, $userId, $e, $q, $total, $acct) {
            $entries = [];
            foreach ([['eos', 'eos_provision', 'eos_expense'], ['leave', 'leave_provision', 'leave_expense'], ['ticket', 'ticket_provision', 'ticket_expense']] as [$c, $prov, $exp]) {
                $held = max(0.0, $q['provision_held'][$c]); $diff = round($q['due'][$c] - $held, 2);
                if ($held > 0) { $entries[] = ['ledger_id' => $this->svc->sys($cid, $prov), 'debit' => $held]; }
                if ($diff > 0) { $entries[] = ['ledger_id' => $this->svc->sys($cid, $exp), 'debit' => $diff]; }
                if ($diff < 0) { $entries[] = ['ledger_id' => $this->svc->sys($cid, $exp), 'credit' => -$diff]; }
            }
            if ($total > 0) { $entries[] = ['ledger_id' => $acct->id, 'credit' => $total]; }
            $vid = null;
            if (count($entries) >= 2) {
                $vid = $this->svc->saveVoucher($cid, $userId, ['type' => 'journal', 'date' => $q['date'], 'sub_type' => 'adjusting', 'source_type' => 'settlement', 'source_id' => $e->id,
                    'entries' => $entries, 'narration' => "End-of-service settlement — {$e->name}"])->id;
            }
            $e->update(['status' => 'left', 'left_on' => $q['date'], 'settled_on' => $q['date'], 'settlement_amount' => $total, 'settlement_voucher_id' => $vid]);
            return $e->fresh();
        });
    }
}
