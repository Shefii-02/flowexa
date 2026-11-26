<?php

namespace App\Modules\Lead\Services;

use App\Models\Lead;
use App\Models\ListingSale;
use App\Modules\Hr\Support\SalesService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A lead's sale paid in instalments: the plan (how many, when, how much each — amounts may differ), payments, waivers, reversals, late fees
 * and the activity log. HR decides (installment_settings) how the money counts toward sales targets:
 *   collected — every payment counts in the month it is paid (a listing_sale per payment)
 *   full      — the whole value counts at enrolment (the existing auto-sale); payments add nothing
 *   first     — only the first installment's payments count
 * Incentives use the same rule unless HR picks a different one (incentive_basis): then targets (listing_sales without an incentive) and
 * incentives (standalone hr_incentives) are credited separately.
 */
class InstallmentService
{
    public const DEFAULTS = [
        'target_basis' => 'collected', 'incentive_basis' => 'same', 'category_follow_basis' => true, 'late_fee_mode' => 'none', 'late_fee_value' => 0, 'late_fee_grace_days' => 0,
        'late_fee_counts_for_target' => false, 'followup_mode' => 'owner_hr', 'collector_user_id' => null,
    ];

    public function __construct(private readonly SalesService $sales) {}

    // ── settings ───────────────────────────────────────────────────────────────
    public function settings(int $companyId): array
    {
        $row = DB::table('installment_settings')->where('company_id', $companyId)->first();
        if (!$row) { return self::DEFAULTS; }
        $a = (array) $row;
        foreach (['category_follow_basis', 'late_fee_counts_for_target'] as $k) { $a[$k] = (bool) $a[$k]; }
        $a['late_fee_value'] = (float) $a['late_fee_value'];

        return array_intersect_key($a, self::DEFAULTS) + self::DEFAULTS;
    }

    public function saveSettings(int $companyId, array $d): array
    {
        $vals = array_intersect_key($d, self::DEFAULTS) + ['updated_at' => now()];
        if (DB::table('installment_settings')->where('company_id', $companyId)->exists()) {
            DB::table('installment_settings')->where('company_id', $companyId)->update($vals);
        } else {
            DB::table('installment_settings')->insert($vals + ['company_id' => $companyId, 'created_at' => now()]);
        }

        return $this->settings($companyId);
    }

    // ── reading ────────────────────────────────────────────────────────────────

    /** Charge the late fee on every installment that is past its due date + grace and still unpaid (done lazily whenever installments are read). */
    public function syncLateFees(int $companyId, ?int $leadId = null): void
    {
        $s = $this->settings($companyId);
        if ($s['late_fee_mode'] === 'none' || (float) $s['late_fee_value'] <= 0) { return; }
        $limit = today()->subDays((int) $s['late_fee_grace_days'])->toDateString();
        $rows = DB::table('lead_installments')->where('company_id', $companyId)->whereIn('status', ['pending', 'partial'])->where('late_fee', 0)
            ->where('due_date', '<', $limit)->when($leadId, fn ($q) => $q->where('lead_id', $leadId))->get();
        foreach ($rows as $r) {
            $fee = $s['late_fee_mode'] === 'percent' ? round((float) $r->amount * $s['late_fee_value'] / 100, 2) : (float) $s['late_fee_value'];
            DB::table('lead_installments')->where('id', $r->id)->update(['late_fee' => $fee, 'updated_at' => now()]);
            $this->log($companyId, (int) $r->lead_id, (int) $r->id, null, 'late_fee', ['fee' => $fee]);
        }
    }

    private function paidOf(int $installmentId): array
    {
        $r = DB::table('lead_installment_payments')->where('installment_id', $installmentId)->whereNull('reversed_at')
            ->selectRaw('COALESCE(SUM(amount),0) paid, COALESCE(SUM(late_fee_amount),0) fee')->first();

        return ['paid' => (float) $r->paid, 'fee_paid' => (float) $r->fee];
    }

    public function decorate(object $i): array
    {
        $p = $this->paidOf((int) $i->id);
        $balance = max(0, round((float) $i->amount - $p['paid'], 2));
        $overdue = in_array($i->status, ['pending', 'partial'], true) && $i->due_date < today()->toDateString();

        return (array) $i + $p + [
            'balance' => $balance, 'fee_due' => max(0, round((float) $i->late_fee - $p['fee_paid'], 2)),
            'overdue' => $overdue, 'days_overdue' => $overdue ? (int) Carbon::parse($i->due_date)->diffInDays(today()) : 0,
        ];
    }

    /** The lead's plan with every installment, payment and log line. */
    public function forLead(int $companyId, int $leadId): array
    {
        $this->syncLateFees($companyId, $leadId);
        $plan = DB::table('lead_installment_plans')->where('company_id', $companyId)->where('lead_id', $leadId)->where('status', '!=', 'cancelled')->orderByDesc('id')->first();
        if (!$plan) { return ['plan' => null, 'installments' => [], 'payments' => [], 'log' => [], 'settings' => $this->settings($companyId)]; }

        $inst = DB::table('lead_installments as i')->leftJoin('users as u', 'u.id', '=', 'i.assigned_to')->where('i.plan_id', $plan->id)->orderBy('i.seq')
            ->get(['i.*', 'u.name as assigned_name'])->map(fn ($i) => $this->decorate($i))->all();
        $payments = DB::table('lead_installment_payments as p')->leftJoin('users as u', 'u.id', '=', 'p.recorded_by')
            ->whereIn('p.installment_id', array_column($inst, 'id'))->orderByDesc('p.id')->get(['p.*', 'u.name as recorded_name'])->all();
        $log = DB::table('lead_installment_logs as l')->leftJoin('users as u', 'u.id', '=', 'l.user_id')->where('l.company_id', $companyId)->where('l.lead_id', $leadId)
            ->orderByDesc('l.id')->limit(100)->get(['l.id', 'l.installment_id', 'l.action', 'l.details', 'l.created_at', 'u.name as by'])
            ->map(fn ($r) => (array) $r + ['details_arr' => json_decode((string) $r->details, true) ?: []])->all();
        $collected = array_sum(array_column($inst, 'paid'));

        return [
            'plan' => (array) $plan + ['collected' => round($collected, 2), 'balance' => round((float) $plan->total_amount - $collected, 2)],
            'installments' => $inst, 'payments' => $payments, 'log' => $log, 'settings' => $this->settings($companyId),
        ];
    }

    // ── writing ────────────────────────────────────────────────────────────────

    public function log(int $companyId, int $leadId, ?int $installmentId, ?int $userId, string $action, array $details = []): void
    {
        DB::table('lead_installment_logs')->insert(['company_id' => $companyId, 'lead_id' => $leadId, 'installment_id' => $installmentId, 'user_id' => $userId,
            'action' => $action, 'details' => json_encode($details), 'created_at' => now()]);
    }

    /** @param array<int,array{due_date:string, amount:float|int|string}> $rows */
    public function createPlan(Lead $lead, float $total, array $rows, ?int $assignTo, ?int $by, ?string $note): array
    {
        if (count($rows) < 1 || count($rows) > 60) { throw ValidationException::withMessages(['installments' => 'A plan needs between 1 and 60 installments.']); }
        $sum = round(array_sum(array_map(fn ($r) => (float) $r['amount'], $rows)), 2);
        if (abs($sum - round($total, 2)) > 0.009) {
            throw ValidationException::withMessages(['installments' => "The installments add up to {$sum} but the total is " . round($total, 2) . '.']);
        }

        // Follow-up defaults to the person who made the sale (the lead's owner today) and stays with them even if the lead moves — change it per
        // installment, or in bulk on the Installments page.
        $assignTo = $assignTo ?: ($lead->assigned_to ? (int) $lead->assigned_to : null);

        DB::transaction(function () use ($lead, $total, $rows, $assignTo, $by, $note) {
            // a new plan replaces any earlier open one (its unpaid installments are dropped; payments already made are kept in the log)
            $old = DB::table('lead_installment_plans')->where('lead_id', $lead->id)->whereIn('status', ['active', 'completed'])->first();
            if ($old) {
                if (DB::table('lead_installment_payments as p')->join('lead_installments as i', 'i.id', '=', 'p.installment_id')->where('i.plan_id', $old->id)->whereNull('p.reversed_at')->exists()) {
                    throw ValidationException::withMessages(['installments' => 'This lead already has payments on its plan. Edit the installments instead of creating a new plan.']);
                }
                DB::table('lead_installment_plans')->where('id', $old->id)->update(['status' => 'cancelled', 'updated_at' => now()]);
            }
            $planId = DB::table('lead_installment_plans')->insertGetId(['company_id' => $lead->company_id, 'lead_id' => $lead->id, 'total_amount' => round($total, 2),
                'status' => 'active', 'created_by' => $by, 'note' => $note, 'created_at' => now(), 'updated_at' => now()]);
            usort($rows, fn ($a, $b) => strcmp($a['due_date'], $b['due_date']));
            foreach ($rows as $n => $r) {
                DB::table('lead_installments')->insert(['company_id' => $lead->company_id, 'plan_id' => $planId, 'lead_id' => $lead->id, 'seq' => $n + 1,
                    'due_date' => $r['due_date'], 'amount' => round((float) $r['amount'], 2), 'assigned_to' => $assignTo, 'created_at' => now(), 'updated_at' => now()]);
            }
            $this->log($lead->company_id, $lead->id, null, $by, 'plan_created', ['total' => round($total, 2), 'count' => count($rows)]);
            $this->rebaseSale($lead, $planId);
        });

        return $this->forLead($lead->company_id, $lead->id);
    }

    /**
     * Record a payment against one installment. Partial payments are fine; anything above the balance is refused.
     * Counts toward the target according to the company's basis.
     */
    public function pay(int $installmentId, int $companyId, float $amount, float $lateFee, string $paidOn, ?string $method, ?string $reference, ?int $by): array
    {
        $i = DB::table('lead_installments')->where('company_id', $companyId)->find($installmentId);
        if (!$i) { abort(404); }
        if ($i->status === 'waived') { throw ValidationException::withMessages(['amount' => 'This installment was waived.']); }
        $this->syncLateFees($companyId, (int) $i->lead_id);
        $i = DB::table('lead_installments')->find($installmentId);
        $d = $this->decorate($i);
        if ($amount < 0 || ($amount <= 0 && $lateFee <= 0)) { throw ValidationException::withMessages(['amount' => 'Enter the amount received.']); }
        if ($amount - $d['balance'] > 0.009) { throw ValidationException::withMessages(['amount' => "The balance of this installment is {$d['balance']}."]); }
        if ($lateFee - $d['fee_due'] > 0.009) { throw ValidationException::withMessages(['late_fee_amount' => "The late fee due is {$d['fee_due']}."]); }

        DB::transaction(function () use ($i, $companyId, $amount, $lateFee, $paidOn, $method, $reference, $by) {
            $pid = DB::table('lead_installment_payments')->insertGetId(['company_id' => $companyId, 'installment_id' => $i->id, 'lead_id' => $i->lead_id, 'amount' => $amount,
                'late_fee_amount' => $lateFee, 'paid_on' => $paidOn, 'method' => $method, 'reference' => $reference, 'recorded_by' => $by, 'created_at' => now(), 'updated_at' => now()]);
            $this->refreshStatus((int) $i->id);
            $this->log($companyId, (int) $i->lead_id, (int) $i->id, $by, 'paid', ['payment_id' => $pid, 'amount' => $amount, 'late_fee' => $lateFee, 'paid_on' => $paidOn, 'method' => $method]);
            $this->countPayment($pid);
        });

        return $this->forLead($companyId, (int) $i->lead_id);
    }

    public function reverse(int $paymentId, int $companyId, string $reason, ?int $by): array
    {
        $p = DB::table('lead_installment_payments')->where('company_id', $companyId)->find($paymentId);
        if (!$p || $p->reversed_at) { abort(404); }
        DB::transaction(function () use ($p, $companyId, $reason, $by) {
            $this->sales->voidIncentives('inst_pay', (int) $p->id);
            if ($p->sale_id && ($sale = ListingSale::find($p->sale_id))) { $this->sales->void($sale); }   // takes the target credit and a still-pending incentive back (refuses if the incentive was already paid)
            DB::table('lead_installment_payments')->where('id', $p->id)->update(['reversed_at' => now(), 'reversed_by' => $by, 'reverse_reason' => $reason, 'sale_id' => null, 'updated_at' => now()]);
            $this->refreshStatus((int) $p->installment_id);
            $this->log($companyId, (int) $p->lead_id, (int) $p->installment_id, $by, 'reversed', ['payment_id' => $p->id, 'amount' => (float) $p->amount, 'reason' => $reason]);
        });

        return $this->forLead($companyId, (int) $p->lead_id);
    }

    public function waive(int $installmentId, int $companyId, string $reason, ?int $by): array
    {
        $i = DB::table('lead_installments')->where('company_id', $companyId)->find($installmentId);
        if (!$i) { abort(404); }
        DB::table('lead_installments')->where('id', $i->id)->update(['status' => 'waived', 'waived_reason' => $reason, 'updated_at' => now()]);
        $this->log($companyId, (int) $i->lead_id, (int) $i->id, $by, 'waived', ['reason' => $reason, 'amount' => (float) $i->amount]);

        return $this->forLead($companyId, (int) $i->lead_id);
    }

    /** Change the amount / due date / follow-up person of one installment (HR). The plan total is re-checked against the other installments. */
    public function edit(int $installmentId, int $companyId, array $d, ?int $by): array
    {
        $i = DB::table('lead_installments')->where('company_id', $companyId)->find($installmentId);
        if (!$i) { abort(404); }
        $upd = array_intersect_key($d, array_flip(['due_date', 'amount', 'assigned_to', 'promised_on']));
        if (isset($upd['amount']) && (float) $upd['amount'] + 0.009 < $this->paidOf((int) $i->id)['paid']) {
            throw ValidationException::withMessages(['amount' => 'The amount cannot be less than what has already been paid on it.']);
        }
        $before = array_intersect_key((array) $i, $upd);
        DB::table('lead_installments')->where('id', $i->id)->update($upd + ['updated_at' => now()]);
        $this->refreshStatus((int) $i->id);
        $this->log($companyId, (int) $i->lead_id, (int) $i->id, $by, isset($upd['assigned_to']) && count($upd) === 1 ? 'assigned' : 'edited', ['from' => $before, 'to' => $upd]);

        return $this->forLead($companyId, (int) $i->lead_id);
    }

    /** Hand a batch of installments' follow-up to another staff member (null = no one in particular: the lead's owner / HR). */
    public function bulkAssign(int $companyId, array $ids, ?int $to, ?int $by): int
    {
        $rows = DB::table('lead_installments')->where('company_id', $companyId)->whereIn('id', $ids)->get(['id', 'lead_id', 'assigned_to']);
        foreach ($rows as $r) {
            DB::table('lead_installments')->where('id', $r->id)->update(['assigned_to' => $to, 'updated_at' => now()]);
            $this->log($companyId, (int) $r->lead_id, (int) $r->id, $by, 'assigned', ['from' => ['assigned_to' => $r->assigned_to], 'to' => ['assigned_to' => $to], 'bulk' => true]);
        }

        return $rows->count();
    }

    public function followup(int $installmentId, int $companyId, ?string $note, ?string $promisedOn, ?int $by): array
    {
        $i = DB::table('lead_installments')->where('company_id', $companyId)->find($installmentId);
        if (!$i) { abort(404); }
        DB::table('lead_installments')->where('id', $i->id)->update(['last_followup_at' => now(), 'promised_on' => $promisedOn, 'updated_at' => now()]);
        $this->log($companyId, (int) $i->lead_id, (int) $i->id, $by, 'followup', array_filter(['note' => $note, 'promised_on' => $promisedOn]));

        return $this->forLead($companyId, (int) $i->lead_id);
    }

    // ── internals ──────────────────────────────────────────────────────────────

    private function refreshStatus(int $installmentId): void
    {
        $i = DB::table('lead_installments')->find($installmentId);
        if (!$i || $i->status === 'waived') { return; }
        $paid = $this->paidOf($installmentId)['paid'];
        $status = $paid + 0.009 >= (float) $i->amount ? 'paid' : ($paid > 0 ? 'partial' : 'pending');
        DB::table('lead_installments')->where('id', $installmentId)->update(['status' => $status]);
        $planOpen = DB::table('lead_installments')->where('plan_id', $i->plan_id)->whereIn('status', ['pending', 'partial'])->exists();
        DB::table('lead_installment_plans')->where('id', $i->plan_id)->where('status', '!=', 'cancelled')->update(['status' => $planOpen ? 'active' : 'completed']);
    }

    /** [target basis, incentive basis] — incentive 'same' follows the target. */
    private function bases(int $companyId): array
    {
        $s = $this->settings($companyId);
        $t = $s['target_basis'];

        return [$t, $s['incentive_basis'] === 'same' ? $t : $s['incentive_basis']];
    }

    /** Does a payment on installment number $seq earn credit under this basis? */
    private function qualifies(string $basis, int $seq): bool
    {
        return $basis === 'collected' || ($basis === 'first' && $seq === 1);
    }

    /**
     * Turn one recorded payment into target credit and/or incentive, each by its own basis. Same basis → one sale carrying its incentive (as
     * always); different bases → a sale without an incentive for the target and a standalone incentive for the incentive.
     */
    private function countPayment(int $paymentId): void
    {
        $p = DB::table('lead_installment_payments')->find($paymentId);
        $lead = Lead::find($p->lead_id);
        if (!$lead || !$lead->assigned_to) { return; }
        $cid = (int) $p->company_id;
        $plan = DB::table('lead_installment_plans as pl')->join('lead_installments as i', 'i.plan_id', '=', 'pl.id')->where('i.id', $p->installment_id)->first(['pl.credit_mode']);
        if ($plan && $plan->credit_mode === 'upfront') { return; }   // the full credit was given (and paid out) before the plan existed

        $s = $this->settings($cid);
        [$tb, $ib] = $this->bases($cid);
        $seq = (int) DB::table('lead_installments')->where('id', $p->installment_id)->value('seq');
        $credit = round((float) $p->amount + ($s['late_fee_counts_for_target'] ? (float) $p->late_fee_amount : 0), 2);
        $toTarget = $this->qualifies($tb, $seq) ? $credit : 0.0;
        $toIncentive = $this->qualifies($ib, $seq) ? $credit : 0.0;
        $base = ['listing_id' => $lead->listing_id, 'lead_id' => $lead->id, 'contact_id' => $lead->contact_id, 'staff_id' => $lead->assigned_to,
            'sold_at' => $p->paid_on, 'item_label' => 'Installment payment', 'note' => "Installment payment #{$p->id}", 'created_by' => $p->recorded_by];

        if ($tb === $ib) {
            if ($toTarget > 0) {
                $sale = $this->sales->record($cid, $base + ['amount' => $toTarget]);
                DB::table('lead_installment_payments')->where('id', $p->id)->update(['sale_id' => $sale->id]);
            }
            return;
        }
        if ($toTarget > 0) {
            $sale = $this->sales->record($cid, $base + ['amount' => $toTarget, 'skip_incentive' => true]);
            DB::table('lead_installment_payments')->where('id', $p->id)->update(['sale_id' => $sale->id]);
        }
        if ($toIncentive > 0) {
            $this->sales->recordIncentive($cid, (int) $lead->assigned_to, $lead->listing_id ? (int) $lead->listing_id : null, $toIncentive, (string) $p->paid_on, 'Installment payment', 'inst_pay', (int) $p->id);
        }
    }

    /**
     * Called when a lead with a plan becomes enrolled. Returns true when the instalment rules handled the credit (so the normal "whole value
     * at enrolment" sale must be skipped); false = nothing special, record the full sale as usual.
     */
    public function handleEnrolment(Lead $lead): bool
    {
        $plan = DB::table('lead_installment_plans')->where('lead_id', $lead->id)->whereIn('status', ['active', 'completed'])->first();
        if (!$plan) { return false; }
        [$tb, $ib] = $this->bases((int) $lead->company_id);
        if ($tb === 'full' && $ib === 'full') { return false; }
        $this->creditFullValue($lead, $plan->id, $tb, $ib, (float) $lead->sale_value, now()->toDateString());

        return true;
    }

    /** The parts of the whole value that count at enrolment: the target when its basis is "full", the incentive when its basis is "full". */
    private function creditFullValue(Lead $lead, int $planId, string $tb, string $ib, float $amount, string $soldAt): void
    {
        $cid = (int) $lead->company_id;
        if ($amount <= 0 || !$lead->assigned_to) { return; }
        $base = ['listing_id' => $lead->listing_id, 'lead_id' => $lead->id, 'contact_id' => $lead->contact_id, 'staff_id' => $lead->assigned_to,
            'amount' => $amount, 'sold_at' => $soldAt, 'note' => 'Full value counted at enrolment (instalment plan).', 'created_by' => auth()->id()];
        if ($tb === 'full') { $this->sales->record($cid, $base + ['skip_incentive' => true]); }
        if ($ib === 'full') { $this->sales->recordIncentive($cid, (int) $lead->assigned_to, $lead->listing_id ? (int) $lead->listing_id : null, $amount, $soldAt, 'Sale (full value at enrolment)', 'inst_full', $planId); }
    }

    /**
     * A plan was created for a lead that was already enrolled (so its whole value was already credited, with its incentive). Take that back and
     * let the payments earn it instead — unless the incentive was already PAID OUT: then the credit stays as it is and the payments add
     * nothing again (credit_mode = upfront), so nobody is paid twice and nothing already paid is undone.
     */
    private function rebaseSale(Lead $lead, int $planId): void
    {
        [$tb, $ib] = $this->bases((int) $lead->company_id);
        if ($tb === 'full' && $ib === 'full') { return; }
        $sale = $lead->sale()->first();
        if (!$sale) { return; }
        $amount = (float) $sale->amount; $soldAt = $sale->sold_at?->toDateString() ?? now()->toDateString();
        try {
            $this->sales->void($sale);
        } catch (\Throwable $e) {
            DB::table('lead_installment_plans')->where('id', $planId)->update(['credit_mode' => 'upfront']);
            $this->log((int) $lead->company_id, (int) $lead->id, null, auth()->id(), 'credit_kept', ['reason' => 'The incentive for the full sale was already paid out, so that credit stays and payments are not counted again.']);
            return;
        }
        $this->creditFullValue($lead, $planId, $tb, $ib, $amount, $soldAt);
    }
}
