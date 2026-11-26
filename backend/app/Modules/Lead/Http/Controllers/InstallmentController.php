<?php

namespace App\Modules\Lead\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Modules\Lead\Services\InstallmentService;
use App\Modules\Lead\Services\LeadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Installments on a lead's sale. HR (hr.manage) controls the rules (target basis, late fee, who follows up) and can edit / waive / reverse;
 * the people who may record payments and log follow-ups come from the "followup_mode" setting:
 *   owner     — the lead's owner (or the installment's assigned person)
 *   owner_hr  — the owner, the assigned person, or HR
 *   collector — the chosen collections person, the assigned person, or HR
 */
class InstallmentController extends Controller
{
    public function __construct(private readonly InstallmentService $svc, private readonly LeadService $leads) {}

    private function hr(): bool { $u = auth()->user(); return $u->isOwner() || $u->hasPermission('hr.manage'); }

    private function lead(int $lead): Lead
    {
        $u = auth()->user();
        return $this->leads->show($lead, $u->company_id, $u->id, $u->hasPermission('leads.view_all') || $this->hr());
    }

    /** May this person record payments / log follow-ups on this installment? */
    private function mayFollowUp(?object $installment, Lead $lead): bool
    {
        $u = auth()->user();
        if ($this->hr()) { return true; }
        $s = $this->svc->settings((int) $u->company_id);
        $assigned = $installment && (int) $installment->assigned_to === (int) $u->id;
        $owner = (int) $lead->assigned_to === (int) $u->id;
        return match ($s['followup_mode']) {
            'owner' => $owner || $assigned,
            'collector' => $assigned || (int) $s['collector_user_id'] === (int) $u->id,
            default => $owner || $assigned,   // owner_hr
        };
    }

    // ── settings (HR) ──────────────────────────────────────────────────────────
    public function settings(): JsonResponse
    {
        return response()->json(['data' => $this->svc->settings((int) auth()->user()->company_id)]);
    }

    public function saveSettings(Request $r): JsonResponse
    {
        abort_unless($this->hr(), 403, 'Only HR can change the installment rules.');
        $d = $r->validate([
            'target_basis' => ['required', 'in:collected,full,first'],
            'incentive_basis' => ['required', 'in:same,collected,full,first'],
            'category_follow_basis' => ['boolean'],
            'late_fee_mode' => ['required', 'in:none,fixed,percent'],
            'late_fee_value' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'late_fee_grace_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'late_fee_counts_for_target' => ['boolean'],
            'followup_mode' => ['required', 'in:owner,owner_hr,collector'],
            'collector_user_id' => ['nullable', 'integer'],
        ]);
        $cid = (int) auth()->user()->company_id;
        if (($d['followup_mode'] ?? '') === 'collector') {
            abort_unless(!empty($d['collector_user_id']) && \App\Models\User::where('company_id', $cid)->whereKey($d['collector_user_id'])->exists(), 422, 'Choose the collections person.');
        } else { $d['collector_user_id'] = null; }
        $d['late_fee_value'] = (float) ($d['late_fee_value'] ?? 0);
        $d['late_fee_grace_days'] = (int) ($d['late_fee_grace_days'] ?? 0);

        return response()->json(['data' => $this->svc->saveSettings($cid, $d)]);
    }

    // ── one lead ───────────────────────────────────────────────────────────────
    public function show(int $lead): JsonResponse
    {
        $l = $this->lead($lead);

        return response()->json($this->svc->forLead((int) $l->company_id, $l->id) + ['can_follow_up' => $this->mayFollowUp(null, $l), 'is_hr' => $this->hr(), 'sale_value' => $l->sale_value]);
    }

    public function createPlan(Request $r, int $lead): JsonResponse
    {
        $l = $this->lead($lead);
        $u = auth()->user();
        abort_unless($this->hr() || $u->hasPermission('leads.manage') || (int) $l->assigned_to === (int) $u->id, 403, 'Only the lead owner or HR can create the plan.');
        $d = $r->validate([
            'total_amount' => ['required', 'numeric', 'min:1', 'max:999999999'],
            'installments' => ['required', 'array', 'min:1', 'max:60'],
            'installments.*.due_date' => ['required', 'date'],
            'installments.*.amount' => ['required', 'numeric', 'min:0.01'],
            'assigned_to' => ['nullable', 'integer'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $assign = !empty($d['assigned_to']) && \App\Models\User::where('company_id', $l->company_id)->whereKey($d['assigned_to'])->exists() ? (int) $d['assigned_to'] : null;

        return response()->json($this->svc->createPlan($l, (float) $d['total_amount'], $d['installments'], $assign, $u->id, $d['note'] ?? null));
    }

    // ── one installment ────────────────────────────────────────────────────────
    private function installment(int $id): array
    {
        $i = DB::table('lead_installments')->where('company_id', auth()->user()->company_id)->find($id);
        abort_unless($i, 404);

        return [$i, $this->lead((int) $i->lead_id)];
    }

    public function pay(Request $r, int $id): JsonResponse
    {
        [$i, $lead] = $this->installment($id);
        abort_unless($this->mayFollowUp($i, $lead), 403, 'You are not set to collect this installment.');
        $d = $r->validate([
            'amount' => ['required', 'numeric', 'min:0', 'max:999999999'], 'late_fee_amount' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'paid_on' => ['nullable', 'date'], 'method' => ['nullable', 'string', 'max:20'], 'reference' => ['nullable', 'string', 'max:100'],
        ]);

        return response()->json($this->svc->pay($id, (int) $i->company_id, (float) $d['amount'], (float) ($d['late_fee_amount'] ?? 0), $d['paid_on'] ?? today()->toDateString(), $d['method'] ?? null, $d['reference'] ?? null, auth()->id()));
    }

    public function followup(Request $r, int $id): JsonResponse
    {
        [$i, $lead] = $this->installment($id);
        abort_unless($this->mayFollowUp($i, $lead), 403, 'You are not set to follow this installment up.');
        $d = $r->validate(['note' => ['nullable', 'string', 'max:500'], 'promised_on' => ['nullable', 'date']]);

        return response()->json($this->svc->followup($id, (int) $i->company_id, $d['note'] ?? null, $d['promised_on'] ?? null, auth()->id()));
    }

    // HR only ─────────────────────────────────────────────────────────────────
    public function edit(Request $r, int $id): JsonResponse
    {
        [$i] = $this->installment($id);
        abort_unless($this->hr(), 403, 'Only HR can edit installments.');
        $d = $r->validate(['due_date' => ['sometimes', 'date'], 'amount' => ['sometimes', 'numeric', 'min:0.01'], 'assigned_to' => ['sometimes', 'nullable', 'integer'], 'promised_on' => ['sometimes', 'nullable', 'date']]);
        if (!empty($d['assigned_to'])) { abort_unless(\App\Models\User::where('company_id', $i->company_id)->whereKey($d['assigned_to'])->exists(), 422, 'Unknown staff member.'); }

        return response()->json($this->svc->edit($id, (int) $i->company_id, $d, auth()->id()));
    }

    /** POST /installments/bulk-assign { ids[], assigned_to|null } — HR or lead managers move many installments to another follow-up person at once. */
    public function bulkAssign(Request $r): JsonResponse
    {
        $u = auth()->user();
        abort_unless($this->hr() || $u->hasPermission('leads.manage'), 403, 'Only HR or a lead manager can switch who follows up.');
        $d = $r->validate(['ids' => ['required', 'array', 'min:1', 'max:500'], 'ids.*' => ['integer'], 'assigned_to' => ['nullable', 'integer']]);
        $to = $d['assigned_to'] ?? null;
        if ($to) { abort_unless(\App\Models\User::where('company_id', $u->company_id)->where('is_active', true)->whereKey($to)->exists(), 422, 'Choose an active staff member.'); }
        $n = $this->svc->bulkAssign((int) $u->company_id, $d['ids'], $to ? (int) $to : null, $u->id);

        return response()->json(['updated' => $n]);
    }

    public function waive(Request $r, int $id): JsonResponse
    {
        [$i] = $this->installment($id);
        abort_unless($this->hr(), 403, 'Only HR can waive installments.');
        $d = $r->validate(['reason' => ['required', 'string', 'max:255']]);

        return response()->json($this->svc->waive($id, (int) $i->company_id, $d['reason'], auth()->id()));
    }

    public function reverse(Request $r, int $paymentId): JsonResponse
    {
        abort_unless($this->hr(), 403, 'Only HR can reverse a payment.');
        $d = $r->validate(['reason' => ['required', 'string', 'max:255']]);

        return response()->json($this->svc->reverse($paymentId, (int) auth()->user()->company_id, $d['reason'], auth()->id()));
    }

    // ── the follow-up page ─────────────────────────────────────────────────────
    /** GET /installments?view=overdue|today|upcoming|paid|all&mine=1 */
    public function index(Request $r): JsonResponse
    {
        $u = auth()->user();
        $cid = (int) $u->company_id;
        $this->svc->syncLateFees($cid);
        $view = (string) $r->query('view', 'overdue');
        $s = $this->svc->settings($cid);
        $all = $this->hr() || $u->hasPermission('leads.view_all');

        $q = DB::table('lead_installments as i')->join('lead_installment_plans as p', 'p.id', '=', 'i.plan_id')->join('leads as l', 'l.id', '=', 'i.lead_id')
            ->leftJoin('contacts as c', 'c.id', '=', 'l.contact_id')->leftJoin('users as a', 'a.id', '=', 'i.assigned_to')->leftJoin('users as o', 'o.id', '=', 'l.assigned_to')
            ->where('i.company_id', $cid)->where('p.status', '!=', 'cancelled')->whereNull('l.deleted_at');

        // who sees what: HR / view-all see everyone's; everyone else only the installments they are set to follow up
        if (!$all || $r->boolean('mine')) {
            $q->where(function ($w) use ($u, $s) {
                $w->where('i.assigned_to', $u->id);
                if ($s['followup_mode'] === 'collector' && (int) $s['collector_user_id'] === (int) $u->id) { $w->orWhereRaw('1 = 1'); }
                elseif ($s['followup_mode'] !== 'collector') { $w->orWhere('l.assigned_to', $u->id); }
            });
        }
        $today = today()->toDateString();
        match ($view) {
            'overdue' => $q->whereIn('i.status', ['pending', 'partial'])->where('i.due_date', '<', $today),
            'today' => $q->whereIn('i.status', ['pending', 'partial'])->where('i.due_date', $today),
            'upcoming' => $q->whereIn('i.status', ['pending', 'partial'])->where('i.due_date', '>', $today),
            'paid' => $q->whereIn('i.status', ['paid', 'waived']),
            default => null,
        };

        $rows = $q->orderBy('i.due_date')->limit(300)->get(['i.*', 'l.id as lead_id', 'c.name as contact_name', 'c.phone as contact_phone', 'a.name as assigned_name', 'o.name as owner_name', 'p.total_amount'])
            ->map(fn ($i) => $this->svc->decorate($i))->all();

        return response()->json(['data' => $rows, 'view' => $view, 'is_hr' => $this->hr(), 'can_switch' => $this->hr() || $u->hasPermission('leads.manage')]);
    }
}
