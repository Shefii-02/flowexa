<?php

namespace App\Modules\Lead\Http\Controllers;

use App\Models\LeadAssignment;
use App\Models\LeadAssignmentNotification;
use App\Models\LeadAssignmentRule;
use App\Models\StaffAvailability;
use App\Models\User;
use App\Jobs\SendLeadNotifications;
use App\Services\LeadAssignment\LeadAssignmentEngine;
use App\Services\LeadAssignment\LeadActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class LeadAssignmentController extends Controller
{
    public function __construct(
        private readonly LeadAssignmentEngine $engine,
        private readonly LeadActivityLogger $activity,
    ) {}

    /** Owner / super admin / anyone with leads.manage may act on any assignment in the company. */
    private function isManager(User $u): bool
    {
        return $u->isSuperAdmin() || $u->isOwner() || $u->hasPermission('leads.manage');
    }

    /** Staff may act on an assignment only if it is theirs or was offered to them; managers on any. */
    private function mayAct(LeadAssignment $a, User $u): bool
    {
        return $this->isManager($u)
            || (int) $a->staff_id === (int) $u->id
            || LeadAssignmentNotification::where('assignment_id', $a->id)->where('staff_id', $u->id)->exists();
    }

    /** Managers see every assignment; staff only their own and the ones offered to them. */
    private function visibleTo($query, User $u)
    {
        if ($this->isManager($u)) { return $query; }
        return $query->where(fn ($q) => $q->where('staff_id', $u->id)
            ->orWhereIn('id', LeadAssignmentNotification::where('staff_id', $u->id)->select('assignment_id')));
    }

    // GET /api/v1/lead-assignments
    public function index(Request $request): JsonResponse
    {
        $company = Auth::user()->company;

        $query = LeadAssignment::where('company_id', $company->id)
            ->with(['contact', 'staff', 'campaign', 'lead:id,source,category,origin_label,created_at'])
            ->orderByDesc('created_at')->orderByDesc('id');
        $this->visibleTo($query, Auth::user());

        if ($request->filled('status'))      $query->where('status', $request->status);
        if ($request->filled('staff_id'))    $query->where('staff_id', $request->staff_id);
        if ($request->filled('contact_id'))  $query->where('contact_id', $request->contact_id);
        if ($request->filled('source_type')) $query->where('source_type', $request->source_type);
        if ($request->filled('campaign_id')) $query->where('campaign_id', $request->campaign_id);
        if ($request->filled('date_from'))   $query->whereDate('created_at', '>=', $request->date_from);
        if ($request->filled('date_to'))     $query->whereDate('created_at', '<=', $request->date_to);

        $user = Auth::user();
        $page = $query->paginate(20);
        // tell the clients which buttons to offer: complete/transfer = it's mine (or I manage), accept/decline = it is currently offered to me
        $offered = LeadAssignmentNotification::where('staff_id', $user->id)->whereNull('responded_at')
            ->whereIn('assignment_id', $page->getCollection()->pluck('id'))->pluck('assignment_id')->all();
        $manager = $this->isManager($user);
        $page->getCollection()->transform(function ($a) use ($user, $manager, $offered) {
            $a->setAttribute('can_act', $manager || (int) $a->staff_id === (int) $user->id);
            $a->setAttribute('offered_to_me', in_array($a->id, $offered, true));
            return $a;
        });

        return response()->json($page);
    }

    // GET /api/v1/lead-assignments/stats
    public function stats(): JsonResponse
    {
        $company = Auth::user()->company;
        $today = now()->startOfDay();

        $base = LeadAssignment::where('company_id', $company->id);

        $staffPerformance = User::where('company_id', $company->id)
            ->where('is_active', true)
            ->with('availability')
            ->get()
            ->map(function (User $staff) use ($company) {
                $avail    = $staff->availability;
                $assigned = LeadAssignment::where('staff_id', $staff->id)->count();
                $converted = LeadAssignment::where('staff_id', $staff->id)->where('status', 'completed')->count();
                return [
                    'staff'        => ['id' => $staff->id, 'name' => $staff->name],
                    'assigned'     => $assigned,
                    'converted'    => $converted,
                    'rate'         => $assigned > 0 ? round(($converted / $assigned) * 100, 1) . '%' : '0%',
                    'avg_response' => $avail?->avg_response_time_minutes ?? 0,
                    'score'        => $avail?->performance_score ?? 50,
                ];
            });

        $avgReply = LeadAssignment::where('company_id', $company->id)
            ->whereNotNull('first_reply_at')
            ->whereNotNull('accepted_at')
            ->get()
            ->avg(fn($a) => $a->accepted_at->diffInSeconds($a->first_reply_at));

        return response()->json([
            'total_today'           => (clone $base)->whereDate('created_at', $today)->count(),
            'auto_assigned'         => (clone $base)->where('assignment_type', 'auto')->count(),
            'notification_assigned' => (clone $base)->where('assignment_type', 'notification')->count(),
            'ai_handling'           => (clone $base)->where('status', 'ai_handling')->count(),
            'sla_breached'          => (clone $base)->where('sla_breached', true)->count(),
            'avg_response_time'     => $avgReply ? gmdate('i\m s\s', (int) $avgReply) : '-',
            'conversion_rate'       => $this->companyConversionRate($company->id),
            'staff_performance'     => $staffPerformance,
        ]);
    }

    // GET /api/v1/lead-assignments/{id}
    public function show(int $id): JsonResponse
    {
        $company = Auth::user()->company;
        $assignment = $this->visibleTo(LeadAssignment::where('company_id', $company->id), Auth::user())
            ->with(['contact', 'staff', 'campaign', 'notifications.staff', 'aiSession'])
            ->findOrFail($id);

        return response()->json(['data' => $assignment]);
    }

    // POST /api/v1/lead-assignments (manual)
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'contact_id'  => 'required|integer',
            'source_type' => 'nullable|in:wa_chat,meta_api,campaign,organic,flow_builder,manual',
            'campaign_id' => ['nullable', 'integer', \Illuminate\Validation\Rule::exists('campaigns', 'id')->where('company_id', Auth::user()->company_id)],
            'staff_id'    => ['nullable', 'integer', \Illuminate\Validation\Rule::exists('users', 'id')->where('company_id', Auth::user()->company_id)],
            'notes'       => 'nullable|string',
        ]);

        $company = Auth::user()->company;
        $contact = \App\Models\Contact::where('company_id', $company->id)
            ->findOrFail($request->contact_id);

        $assignment = $this->engine->assign(
            $company,
            $contact,
            $request->source_type ?? 'manual',
            $request->campaign_id,
            null,
            'manual'
        );

        if ($request->filled('staff_id')) {
            $staff = User::where('company_id', $company->id)->findOrFail($request->staff_id);
            $rule  = LeadAssignmentRule::where('company_id', $company->id)->firstOrNew();
            $this->engine->assignToStaff($assignment, $staff, $rule, 'manual');
        }

        if ($request->filled('notes')) {
            $assignment->update(['notes' => $request->notes]);
        }

        return response()->json(['data' => $assignment->fresh(['contact', 'staff', 'campaign'])], 201);
    }

    // PUT /api/v1/lead-assignments/{id}
    public function update(Request $request, int $id): JsonResponse
    {
        $company    = Auth::user()->company;
        $assignment = LeadAssignment::where('company_id', $company->id)->findOrFail($id);
        abort_unless($this->mayAct($assignment, Auth::user()), 403, 'This lead is not assigned to you.');

        $data = $request->validate([
            'status' => ['sometimes', 'in:pending,notified,accepted,assigned,ai_handling,ai_offered,transferred,completed,dropped'],
            'notes'  => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);
        $assignment->update($data);
        return response()->json(['data' => $assignment->fresh(['contact', 'staff'])]);
    }

    // POST /api/v1/lead-assignments/{id}/accept
    public function accept(int $id): JsonResponse
    {
        $user    = Auth::user();
        $company = $user->company;

        $assignment = DB::transaction(function () use ($id, $user, $company) {
            // row lock: two staff pressing Accept at once — only the first wins
            $assignment = LeadAssignment::where('company_id', $company->id)->lockForUpdate()->findOrFail($id);
            abort_unless($this->mayAct($assignment, $user), 403, 'This lead was not offered to you.');

            $takenByOther = $assignment->staff_id && (int) $assignment->staff_id !== (int) $user->id
                && in_array($assignment->status, ['assigned', 'accepted', 'completed'], true);
            abort_if($takenByOther || in_array($assignment->status, ['completed', 'dropped'], true), 409, 'This lead has already been taken.');

            LeadAssignmentNotification::where('assignment_id', $id)
                ->where('staff_id', $user->id)
                ->whereNull('responded_at')
                ->update(['response' => 'accepted', 'responded_at' => now()]);

            // the instant-assign path already counted this lead against the staff member; an accepted offer has not been counted yet
            $alreadyCounted = (int) $assignment->staff_id === (int) $user->id;
            $assignment->update(['staff_id' => $user->id, 'status' => 'accepted', 'accepted_at' => $assignment->accepted_at ?? now(), 'assignment_method' => $assignment->assignment_method ?: (LeadAssignmentRule::where('company_id', $company->id)->value('strategy') ?: 'algorithm')]);
            if (!$alreadyCounted) { $this->engine->bumpWorkload($company->id, $user); }
            return $assignment;
        });

        $this->activity->syncAssignee($assignment, $user->id);
        $this->activity->log($assignment, 'lead_assignment_accepted', ['staff_id' => $user->id, 'staff_name' => $user->name], $user->id);
        $this->engine->syncConversationOwner($assignment, $user->id);

        return response()->json(['data' => $assignment->fresh()]);
    }

    // POST /api/v1/lead-assignments/{id}/decline
    public function decline(int $id): JsonResponse
    {
        $user    = Auth::user();
        $company = $user->company;

        $assignment = LeadAssignment::where('company_id', $company->id)->findOrFail($id);

        $declined = LeadAssignmentNotification::where('assignment_id', $id)
            ->where('staff_id', $user->id)
            ->whereNull('responded_at')
            ->update(['response' => 'declined', 'responded_at' => now()]);
        // only a staff member this lead is currently offered to can decline it (otherwise anyone could re-trigger the cascade)
        abort_if($declined === 0, 403, 'This lead is not currently offered to you.');

        $this->activity->log($assignment, 'lead_assignment_declined', ['staff_id' => $user->id, 'staff_name' => $user->name], $user->id);

        // A decline is exactly like a notification timeout — hand off to the next candidate
        // (round robin or weighted, per the rule) rather than leaving the lead stranded.
        if (!in_array($assignment->status, ['accepted', 'completed', 'dropped'], true)) {
            $rule = LeadAssignmentRule::where('company_id', $company->id)->first();
            if ($rule) {
                dispatch(new SendLeadNotifications($assignment->id, $rule->id))
                    ->delay(now()->addSeconds($rule->notification_gap_seconds));
            }
        }

        return response()->json(['message' => 'Declined. Offering this lead to another team member.']);
    }

    // POST /api/v1/lead-assignments/{id}/complete
    public function complete(int $id): JsonResponse
    {
        $user       = Auth::user();
        $company    = $user->company;
        $assignment = LeadAssignment::where('company_id', $company->id)->findOrFail($id);
        abort_unless($this->isManager($user) || (int) $assignment->staff_id === (int) $user->id, 403, 'Only the assigned staff member or a manager can complete this lead.');

        // idempotent: completing twice must not decrement the workload / bump conversions twice
        if ($assignment->status === 'completed') {
            return response()->json(['data' => $assignment->fresh()]);
        }

        $assignment->update([
            'previous_status'     => $assignment->status,
            'previous_lead_stage' => $assignment->contact?->lead_stage,
            'status'              => 'completed',
        ]);
        $this->activity->log($assignment, 'lead_assignment_completed', ['staff_id' => $assignment->staff_id], $user->id);

        if ($assignment->contact) {
            $assignment->contact->update(['lead_stage' => 'converted']);
        }

        if ($assignment->staff_id) {
            $avail = StaffAvailability::where('company_id', $company->id)->where('staff_id', $assignment->staff_id)->first();
            if ($avail && $avail->current_leads_count > 0) {
                $avail->decrement('current_leads_count');
                $avail->increment('today_conversions');
                $avail->increment('total_conversions');
                // If was busy, free up
                if ($avail->fresh()->status === 'busy') {
                    $avail->update(['status' => 'online', 'is_available' => true]);
                }
            }
        }

        return response()->json(['data' => $assignment->fresh()]);
    }

    // POST /api/v1/lead-assignments/{id}/restore — undo "Complete" (back to its old status) or undo the last "Transfer" (back to the previous staff)
    public function restore(int $id): JsonResponse
    {
        $user       = Auth::user();
        $company    = $user->company;
        $assignment = LeadAssignment::where('company_id', $company->id)->findOrFail($id);
        abort_unless($this->isManager($user) || (int) $assignment->staff_id === (int) $user->id, 403, 'Only the assigned staff member or a manager can restore this lead.');

        if ($assignment->status === 'completed') {
            $assignment->update(['status' => $assignment->previous_status ?: 'assigned', 'previous_status' => null]);
            if ($assignment->contact) {
                $assignment->contact->update(['lead_stage' => $assignment->previous_lead_stage ?: 'new']);
            }
            if ($assignment->staff_id) {
                $avail = StaffAvailability::where('company_id', $company->id)->where('staff_id', $assignment->staff_id)->first();
                if ($avail) {
                    $avail->increment('current_leads_count');
                    if ($avail->total_conversions > 0) { $avail->decrement('total_conversions'); }
                    if ($avail->today_conversions > 0) { $avail->decrement('today_conversions'); }
                }
            }
            $this->activity->log($assignment, 'lead_assignment_restored', ['from' => 'completed', 'to' => $assignment->status], $user->id);
        } elseif ($assignment->transferred_from && !in_array($assignment->status, ['dropped'], true)) {
            $back = User::where('company_id', $company->id)->find($assignment->transferred_from);
            abort_unless($back, 422, 'The previous staff member no longer exists.');
            $current = $assignment->staff_id;
            if ($current) {
                $avail = StaffAvailability::where('staff_id', $current)->first();
                if ($avail && $avail->current_leads_count > 0) { $avail->decrement('current_leads_count'); }
            }
            $rule = LeadAssignmentRule::where('company_id', $company->id)->firstOrNew();
            $this->engine->assignToStaff($assignment, $back, $rule, 'restore');
            $assignment->update(['transferred_from' => null, 'transfer_reason' => null]);
            $this->activity->log($assignment, 'lead_assignment_restored', ['from' => 'transfer', 'to_staff_id' => $back->id, 'to_staff_name' => $back->name], $user->id);
        } else {
            abort(422, 'Nothing to restore.');
        }

        return response()->json(['data' => $assignment->fresh(['contact', 'staff', 'lead:id,source,category,origin_label,created_at'])]);
    }

    // POST /api/v1/lead-assignments/{id}/transfer
    public function transfer(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'to_staff_id' => ['required', 'integer', \Illuminate\Validation\Rule::exists('users', 'id')->where('company_id', Auth::user()->company_id)],
            'reason'      => 'nullable|string|max:500',
        ]);

        $company    = Auth::user()->company;
        $assignment = LeadAssignment::where('company_id', $company->id)->findOrFail($id);
        abort_unless($this->isManager(Auth::user()) || (int) $assignment->staff_id === (int) Auth::id(), 403, 'Only the assigned staff member or a manager can transfer this lead.');
        abort_if(in_array($assignment->status, ['completed', 'dropped'], true), 422, 'This lead is already closed.');
        $newStaff   = User::where('company_id', $company->id)->findOrFail($request->to_staff_id);
        $rule       = LeadAssignmentRule::where('company_id', $company->id)->firstOrNew();

        $oldStaffId = $assignment->staff_id;
        $oldStaffName = $oldStaffId ? User::find($oldStaffId)?->name : null;
        $assignment->update([
            'transferred_from' => $oldStaffId,
            'transfer_reason'  => $request->reason ?? 'Manual transfer',
            'status'           => 'transferred',
        ]);

        if ($oldStaffId) {
            $oldAvail = StaffAvailability::where('staff_id', $oldStaffId)->first();
            if ($oldAvail && $oldAvail->current_leads_count > 0) {
                $oldAvail->decrement('current_leads_count');
                if ($oldAvail->fresh()->status === 'busy') {
                    $oldAvail->update(['status' => 'online', 'is_available' => true]);
                }
            }
        }

        $this->engine->assignToStaff($assignment, $newStaff, $rule, 'transfer');
        $this->activity->log($assignment, 'lead_assignment_transferred', [
            'from_staff_id' => $oldStaffId, 'from_staff_name' => $oldStaffName,
            'to_staff_id'   => $newStaff->id, 'to_staff_name' => $newStaff->name,
            'reason'        => $request->reason,
        ], Auth::id());

        return response()->json(['data' => $assignment->fresh(['contact', 'staff'])]);
    }

    // GET /api/v1/lead-assignment-rules
    public function getRule(): JsonResponse
    {
        $company = Auth::user()->company;
        $rule    = LeadAssignmentRule::firstOrCreate(
            ['company_id' => $company->id],
            LeadAssignmentRule::defaultForCompany($company->id)
        );

        return response()->json(['data' => $this->ruleWithSchedule($rule->fresh() ?? $rule)]);
    }

    // POST /api/v1/lead-assignment-rules
    public function saveRule(Request $request): JsonResponse
    {
        $company = Auth::user()->company;
        $rule    = LeadAssignmentRule::firstOrCreate(
            ['company_id' => $company->id],
            LeadAssignmentRule::defaultForCompany($company->id)
        );

        // The plan decides which assignment methods may be switched on (Plans → Lead assign methods).
        $allowed = \App\Support\Entitlements::forCompany($company)['assign_methods'];
        $wanted = array_filter([$request->input('strategy'), $request->input('notification_mode') === 'hybrid' ? 'uber' : $request->input('notification_mode')]);
        if ($request->boolean('auto_assign_enabled') === false && $request->has('auto_assign_enabled')) { $wanted = ['manual']; }
        foreach ($wanted as $m) {
            if (isset(\App\Support\Modules::ASSIGN_METHODS[$m]) && !in_array($m, $allowed, true)) {
                return response()->json(['message' => '"' . \App\Support\Modules::ASSIGN_METHODS[$m] . '" assignment is not part of your plan. Upgrade your plan to use it.', 'error_code' => 'plan_feature_disabled', 'feature' => 'assign_' . $m], 403);
            }
        }

        $cid = $company->id;
        $request->validate([
            'staff_scope'   => ['sometimes', 'in:all,online,selected,selected_online'],
            'staff_ids'     => ['sometimes', 'nullable', 'array'],
            'staff_ids.*'   => ['integer', \Illuminate\Validation\Rule::exists('users', 'id')->where('company_id', $cid)],
        ]);
        if ($request->input('staff_scope') !== $rule->staff_scope   // only a NEW choice is refused — a saved one that HR no longer backs just falls back to round robin
            && in_array($request->input('staff_scope'), \App\Services\LeadAssignment\StaffPoolResolver::ATTENDANCE_SCOPES, true) && !app(\App\Services\LeadAssignment\StaffPoolResolver::class)->attendanceAvailable($cid)) {
            return response()->json(['message' => 'Attendance-based assignment needs the HR section, which is not available on your plan.', 'error_code' => 'hr_not_available'], 422);
        }
        if (in_array($request->input('staff_scope'), ['selected', 'selected_online'], true) && empty($request->input('staff_ids'))) {   // filled() is true for an empty list
            return response()->json(['message' => 'Pick at least one employee, or choose "All staff" / "Attendance-online staff".', 'errors' => ['staff_ids' => ['Pick at least one employee.']]], 422);
        }

        $rule->update($request->only([
            'auto_assign_enabled', 'strategy', 'staff_scope', 'staff_ids',
            'weight_availability', 'weight_max_leads', 'weight_performance', 'weight_workload',
            'sla_minutes', 'ai_takeover_after_minutes',
            'notification_mode', 'notification_gap_seconds', 'notification_timeout_seconds', 'max_notification_rounds',
            'duplicate_window_days', 'duplicate_action',
            'working_hours_start', 'working_hours_end', 'working_days', 'timezone',
            'auto_assign_run_mode', 'auto_assign_interval_minutes', 'auto_assign_run_start', 'auto_assign_run_end',
        ]));

        return response()->json(['data' => $this->ruleWithSchedule($rule->fresh())]);
    }

    /** The rule plus when its auto-assign cron last ran / runs next. */
    private function ruleWithSchedule(LeadAssignmentRule $rule): array
    {
        return $rule->toArray() + ['hr_available' => app(\App\Services\LeadAssignment\StaffPoolResolver::class)->attendanceAvailable($rule->company_id), 'next_run_at' => app(\App\Services\LeadAssignment\ScheduledAutoAssigner::class)->nextRunAt($rule)?->toIso8601String()];
    }

    // POST /api/v1/lead-assignment-rules/run-now — run the auto-assign cron for this company right now
    public function runNow(): JsonResponse
    {
        $company = Auth::user()->company;
        $rule = LeadAssignmentRule::firstOrCreate(['company_id' => $company->id], LeadAssignmentRule::defaultForCompany($company->id));
        abort_unless($rule->auto_assign_enabled, 422, 'Auto-assign is switched off.');
        $r = app(\App\Services\LeadAssignment\ScheduledAutoAssigner::class)->runForCompany($company);
        return response()->json(['message' => "{$r['routed']} lead(s) routed" . ($r['found'] === 0 ? ' — nothing was waiting.' : '.'), ...$r, 'data' => $this->ruleWithSchedule($rule->fresh())]);
    }

    // ── Category / campaign routing rules ─────────────────────────────────────
    private function routingPayload(Request $request, ?\App\Models\LeadRoutingRule $existing = null): array
    {
        $cid = Auth::user()->company_id;
        $d = $request->validate([
            'name'        => 'required|string|max:120',
            'match_type'  => 'required|in:category,campaign',
            'category'    => 'required_if:match_type,category|nullable|string|max:120',
            'campaign_id' => ['nullable', 'integer', \Illuminate\Validation\Rule::exists('campaigns', 'id')->where('company_id', $cid)],
            'starts_at'   => 'nullable|date',
            'ends_at'     => 'nullable|date|after_or_equal:starts_at',
            'staff_scope' => 'required|in:all,online,selected,selected_online',
            'staff_ids'   => 'required_if:staff_scope,selected,selected_online|nullable|array',
            'staff_ids.*' => ['integer', \Illuminate\Validation\Rule::exists('users', 'id')->where('company_id', $cid)],
            'strategy'    => 'nullable|in:algorithm,round_robin',
            'is_active'   => 'boolean',
            'priority'    => 'nullable|integer|min:1|max:9999',
        ]);
        abort_if($d['match_type'] === 'campaign' && empty($d['campaign_id']) && empty($d['starts_at']) && empty($d['ends_at']), 422, 'Pick a campaign or set a period.');
        if ($d['match_type'] === 'category') { $d['campaign_id'] = null; } else { $d['category'] = null; }
        $d['staff_ids'] = in_array($d['staff_scope'], ['selected', 'selected_online'], true) ? array_values(array_unique(array_map('intval', $d['staff_ids'] ?? []))) : [];
        abort_if(in_array($d['staff_scope'], ['selected', 'selected_online'], true) && !$d['staff_ids'], 422, 'Pick at least one employee.');
        abort_if($d['staff_scope'] !== $existing?->staff_scope && in_array($d['staff_scope'], \App\Services\LeadAssignment\StaffPoolResolver::ATTENDANCE_SCOPES, true) && !app(\App\Services\LeadAssignment\StaffPoolResolver::class)->attendanceAvailable($cid), 422, 'Attendance-based assignment needs the HR section, which is not available on your plan.');
        return $d;
    }

    // GET /api/v1/lead-assignment-rules/routing
    public function routingIndex(): JsonResponse
    {
        $rules = \App\Models\LeadRoutingRule::where('company_id', Auth::user()->company_id)->orderBy('priority')->orderBy('id')->get();
        $names = User::whereIn('id', $rules->pluck('staff_ids')->flatten()->filter()->unique())->pluck('name', 'id');
        $camps = \App\Models\Campaign::whereIn('id', $rules->pluck('campaign_id')->filter())->pluck('name', 'id');
        return response()->json(['data' => $rules->map(fn ($r) => array_merge($r->toArray(), [
            'staff_names'   => collect($r->staff_ids ?? [])->map(fn ($i) => $names[$i] ?? "#$i")->values(),
            'campaign_name' => $r->campaign_id ? ($camps[$r->campaign_id] ?? "#{$r->campaign_id}") : null,
        ]))]);
    }

    public function routingStore(Request $request): JsonResponse
    {
        $rule = \App\Models\LeadRoutingRule::create($this->routingPayload($request) + ['company_id' => Auth::user()->company_id]);
        return response()->json(['data' => $rule], 201);
    }

    public function routingUpdate(Request $request, int $id): JsonResponse
    {
        $rule = \App\Models\LeadRoutingRule::where('company_id', Auth::user()->company_id)->findOrFail($id);
        $rule->update($this->routingPayload($request, $rule));
        return response()->json(['data' => $rule]);
    }

    public function routingDestroy(int $id): JsonResponse
    {
        \App\Models\LeadRoutingRule::where('company_id', Auth::user()->company_id)->findOrFail($id)->delete();
        return response()->json(['message' => 'Routing rule deleted.']);
    }

    // GET /api/v1/staff/availability
    public function staffAvailability(): JsonResponse
    {
        $company = Auth::user()->company;

        $staff = User::where('company_id', $company->id)
            ->where('is_active', true)
            ->with(['availability', 'role'])
            ->get()
            ->map(function (User $s) use ($company) {
                $avail = $s->availability ?? StaffAvailability::ensureExists($company->id, $s->id);
                return array_merge($s->toArray(), ['availability' => $avail]);
            });

        return response()->json(['data' => $staff]);
    }

    // POST /api/v1/staff/availability/toggle
    public function toggleAvailability(): JsonResponse
    {
        $user  = Auth::user();
        $avail = StaffAvailability::ensureExists($user->company_id, $user->id);

        // flip what they currently ARE (available = flagged available AND online), not just the flag — otherwise a row
        // that is flagged available but still "offline" needed two presses to come online
        $newAvail = !$avail->isEffectivelyAvailable();
        $avail->update([
            'is_available' => $newAvail,
            'is_online'    => $newAvail,
            'status'       => $newAvail ? 'online' : 'away',
            'last_seen_at' => now(),
        ]);

        return response()->json(['data' => $avail->fresh()]);
    }

    private function companyConversionRate(int $companyId): string
    {
        $total     = LeadAssignment::where('company_id', $companyId)->count();
        $completed = LeadAssignment::where('company_id', $companyId)->where('status', 'completed')->count();
        return $total > 0 ? round(($completed / $total) * 100, 1) . '%' : '0%';
    }
}
