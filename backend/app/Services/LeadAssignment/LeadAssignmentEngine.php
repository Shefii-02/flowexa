<?php

namespace App\Services\LeadAssignment;

use App\Jobs\CheckLeadSla;
use App\Jobs\NotifyStaffNewLead;
use App\Jobs\SendLeadNotifications;
use App\Models\Company;
use App\Models\CompanyHoliday;
use App\Models\CompanyWorkingHour;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\LeadAssignment;
use App\Models\LeadAssignmentRule;
use App\Models\LeadRoutingRule;
use App\Models\StaffAvailability;
use App\Models\User;
use App\Models\WaConversation;
use Illuminate\Support\Carbon;
use App\Modules\WaChat\Models\AiAgentSession;
use Illuminate\Support\Facades\DB;

class LeadAssignmentEngine
{
    public function __construct(
        private readonly StaffScorer $scorer,
        private readonly DuplicateLeadDetector $detector,
        private readonly LeadActivityLogger $activity,
        private readonly StaffPoolResolver $pools,
    ) {}

    public function assign(
        Company $company,
        Contact $contact,
        string $sourceType = 'organic',
        ?int $campaignId = null,
        ?string $sourceRef = null,
        string $assignmentType = 'auto',
        ?Lead $lead = null,
        ?array $onlyStaffIds = null,   // limit the candidate pool (WA Cloud reply routing); null = any eligible staff
        ?string $strategyOverride = null,   // manual "Auto assign" button: 'algorithm' | 'round_robin' — assigns instantly, ignores working hours
        array|bool|null $poolOverride = false,   // manual button only: false = follow the routing rules / default pool, null = every available staff member, array = exactly these (see StaffPoolResolver)
    ): LeadAssignment {
        $rule = LeadAssignmentRule::where('company_id', $company->id)->first()
            ?? LeadAssignmentRule::create(LeadAssignmentRule::defaultForCompany($company->id));

        if ($strategyOverride) {
            // in-memory only (never saved): the admin picked the method for this click
            $rule->strategy = $strategyOverride;
            $rule->notification_mode = 'auto';
        }

        // Category / campaign routing: matching leads go only to the employees (and, optionally,
        // the method) set on a routing rule. An explicit pool from the caller (WA Cloud) wins.
        $routing = LeadRoutingRule::matchFor($company->id, $lead?->categoryNames(), $campaignId ?? $lead?->campaign_id);
        if ($routing && $routing->strategy && !$strategyOverride) {
            $rule->strategy = $routing->strategy;   // in-memory only
        }

        // Who may receive the lead: the matching routing rule's staff choice, else the company-wide one
        // (all / attendance-online / selected / selected + online). A pool the caller passed in (WA Cloud) wins.
        // Attendance-based pool but no HR section → everyone (or the picked staff), assigned round robin — the simple method
        $poolScope = $routing ? ($routing->staff_scope ?: 'selected') : $rule->staff_scope;
        if ($poolOverride === false && !$onlyStaffIds && !$strategyOverride && $this->pools->fallsBackToEveryone($company->id, $poolScope)) {
            $rule->strategy = 'round_robin';   // in-memory only
        }

        if ($poolOverride !== false) {
            $onlyStaffIds = $poolOverride;   // the admin chose the pool for this click — it beats rules and the default pool
        } elseif (!$onlyStaffIds) {
            $onlyStaffIds = $routing
                ? $this->pools->resolve($company->id, $routing->staff_scope ?: 'selected', $routing->staff_ids)
                : $this->pools->resolve($company->id, $rule->staff_scope, $rule->staff_ids);
        }

        $dupCheck = $this->detector->check($company, $contact, $campaignId);

        // Handle duplicate according to rule
        if ($dupCheck['is_duplicate']) {
            match ($dupCheck['recommended_action']) {
                'merge' => null, // fall through to create (we still create a new record for tracking)
                'notify_admin' => null,
                default => null,
            };

            // If assign_same_staff and previous staff is available, route there
            if (
                $dupCheck['recommended_action'] === 'assign_same_staff' &&
                $dupCheck['previous_staff'] !== null
            ) {
                $assignmentType = 'auto';
            }
        }

        return DB::transaction(function () use (
            $company, $contact, $campaignId, $sourceType, $sourceRef,
            $assignmentType, $rule, $dupCheck, $lead, $onlyStaffIds, $strategyOverride, $routing, $poolOverride
        ) {
            $resolvedType = $rule->notification_mode === 'uber' ? 'notification' : $assignmentType;

            $assignment = LeadAssignment::create([
                'company_id'           => $company->id,
                'contact_id'           => $contact->id,
                'lead_id'              => $lead?->id,
                'campaign_id'          => $campaignId,
                'source_type'          => LeadAssignment::mapLeadSource($sourceType),
                'source_ref'           => $sourceRef,
                'status'               => 'pending',
                'assignment_type'      => $resolvedType,
                'priority'             => $this->calculatePriority($contact, $dupCheck),
                'response_sla_minutes' => $rule->sla_minutes,
                'candidate_staff_ids'  => $onlyStaffIds ?: null,
                'routing_strategy'     => $rule->strategy,
                'routing_rule_id'      => $routing?->id,
                'notes'                => $dupCheck['is_duplicate'] ? $dupCheck['reason'] : null,
            ]);

            $updatePayload = [
                'current_assignment_id' => $assignment->id,
                'last_lead_at'          => now(),
            ];
            if (!$contact->first_lead_at) {
                $updatePayload['first_lead_at'] = now();
            }
            // increment() — a DB::raw() inside update() cannot pass the model's integer cast on total_leads_count
            $contact->increment('total_leads_count', 1, $updatePayload);

            // Outside the company's working hours (per-weekday schedule + holiday
            // overrides) → hand straight to the AI agent so the lead isn't dropped.
            if (!$strategyOverride && !$this->withinWorkingHours($company, $rule)) {
                $this->startAiAgent($assignment, $company, $contact);
                $assignment->update(['transfer_reason' => 'Received outside working hours — AI agent engaged.']);
                return $assignment;
            }

            // Same-contact duplicate whose rule says "keep the same staff" → route straight back
            // to them (skipping the notify ceremony) as long as they can still take it, for both
            // notification modes below.
            if ($dupCheck['is_duplicate'] && $dupCheck['recommended_action'] === 'assign_same_staff' && $dupCheck['previous_staff']) {
                $prevStaff = $dupCheck['previous_staff'];
                $prevAllowed = !$onlyStaffIds || in_array($prevStaff->id, $onlyStaffIds, true);
                $prevAvail = StaffAvailability::ensureExists($company->id, $prevStaff->id);
                $prevScore = $this->scorer->score($prevStaff, $prevAvail, $rule, $dupCheck['previous_assignment']);
                if ($prevAllowed && $prevScore > 0) {
                    $this->assignToStaff($assignment, $prevStaff, $rule, 'same_staff');
                    return $assignment;
                }
            }

            // notification_mode "auto" is the only mode that skips the accept/reject ceremony — it
            // instant-assigns via whichever strategy the rule uses (round robin or the weighted
            // algorithm), falling back to the AI agent only if nobody is available at all.
            if ($rule->notification_mode === 'auto') {
                // An admin who picked the pool by hand (the Auto assign button) means those people — online status is not asked.
                // Capacity, an active account and access to leads still apply.
                $ignoreAvailability = $poolOverride !== false;
                $staff = ($rule->strategy ?? 'algorithm') === 'round_robin'
                    ? $this->roundRobinPick($company, [], $onlyStaffIds ?? [], $ignoreAvailability)
                    : ($this->scorer->rankStaff($company, $rule, $dupCheck['previous_assignment'] ?? null, [], $onlyStaffIds ?? [], $ignoreAvailability)->first()->staff ?? null);

                if ($staff) {
                    $this->assignToStaff($assignment, $staff, $rule);
                } elseif ($strategyOverride) {
                    // manual run: leave the lead exactly as it was (the transaction rolls back) — never quietly hand it to the AI agent
                    throw new NoEligibleStaffException('No staff could take this lead.');
                } else {
                    $assignment->update(['transfer_reason' => 'No staff were available (offline, away, at capacity or outside the chosen staff) — AI agent engaged.']);
                    $this->startAiAgent($assignment, $company, $contact);
                }
                return $assignment;
            }

            // "hybrid" and "uber" both notify one staff member at a time — in round-robin or
            // weighted-score order per the rule's strategy (see SendLeadNotifications) — and wait
            // for an explicit Accept/Decline, cascading to the next candidate on decline or on
            // notification_timeout_seconds with no response, until max_notification_rounds is hit
            // and the AI agent takes over.
            dispatch(new SendLeadNotifications($assignment->id, $rule->id));
            return $assignment;
        });
    }

    public function assignToStaff(LeadAssignment $assignment, User $staff, LeadAssignmentRule $rule, ?string $method = null): void
    {
        $method ??= $rule->strategy ?: 'algorithm';
        $wasTransfer = $assignment->staff_id && $assignment->staff_id !== $staff->id && $assignment->exists;

        $assignment->update([
            'staff_id'          => $staff->id,
            'status'            => 'assigned',
            'accepted_at'       => now(),
            'assignment_method' => $method,
        ]);

        $this->bumpWorkload($assignment->company_id, $staff);

        $this->activity->syncAssignee($assignment, $staff->id);
        if (!$wasTransfer) {
            $this->activity->log($assignment, 'lead_assigned', ['staff_id' => $staff->id, 'staff_name' => $staff->name, 'method' => $method, 'mode' => $assignment->assignment_type, 'routing_rule' => $assignment->routing_rule_id ? LeadRoutingRule::find($assignment->routing_rule_id)?->name : null]);
        }

        $this->syncConversationOwner($assignment, $staff->id);

        dispatch(new NotifyStaffNewLead($assignment->id, $staff->id));

        dispatch(new CheckLeadSla($assignment->id))
            ->delay(now()->addMinutes($assignment->response_sla_minutes));
    }

    /** One more open lead for this staff member; flips them to busy at their max_leads. */
    public function bumpWorkload(int $companyId, User $staff): void
    {
        $availability = StaffAvailability::ensureExists($companyId, $staff->id);
        $availability->increment('current_leads_count');
        $availability->increment('today_leads_count');

        if ($staff->max_leads > 0 && $availability->fresh()->current_leads_count >= $staff->max_leads) {
            $availability->update(['status' => 'busy', 'is_available' => false]);
        }
    }

    /**
     * Keeps the WA Cloud shared inbox in step with who actually owns this lead —
     * ConversationController scopes both the inbox list and replying to
     * WaConversation.assigned_to, so every path that hands a lead to a staff member
     * (auto-assign, manual transfer, accepting a notification) moves the matching
     * conversation(s) for this contact to the same person, rather than leaving inbox
     * access as a separately-claimed thing that can drift from who the lead is actually
     * assigned to. WA Chat has no equivalent Laravel-side conversation table (its chat UI
     * talks to the gateway directly) — see the assignment indicator added there instead.
     */
    public function syncConversationOwner(LeadAssignment $assignment, int $staffId): void
    {
        if (!$assignment->contact_id) {
            return;
        }

        WaConversation::where('company_id', $assignment->company_id)
            ->where('contact_id', $assignment->contact_id)
            ->update(['assigned_to' => $staffId]);
    }

    public function startAiAgent(LeadAssignment $assignment, Company $company, Contact $contact): void
    {
        $session = AiAgentSession::create([
            'company_id'      => $company->id,
            'contact_phone'   => $contact->phone,
            'status'          => 'active',
            'waha_session_id' => null,
        ]);

        $assignment->update([
            'status'               => 'ai_handling',
            'ai_takeover_at'       => now(),
            'ai_agent_session_id'  => $session->id,
        ]);

        $this->activity->log($assignment, 'lead_ai_handoff', ['reason' => $assignment->transfer_reason]);
    }

    /** Least-loaded available staff member, then longest-idle (round-robin) — optionally skipping candidates already tried for this assignment. */
    public function roundRobinPick(Company $company, array $excludeStaffIds = [], array $onlyStaffIds = [], bool $ignoreAvailability = false): ?User
    {
        if ($ignoreAvailability) {
            return $this->pickIgnoringAvailability($company, $excludeStaffIds, $onlyStaffIds);
        }

        // Round robin reads staff_availability rows — but a staff member who has never been routed to has none yet, so they
        // were invisible and every lead fell through to the AI agent. Give anyone who can hold leads a row first.
        User::where('company_id', $company->id)->where('is_active', true)
            ->when($onlyStaffIds, fn ($q) => $q->whereIn('id', $onlyStaffIds))
            ->whereDoesntHave('availability')
            ->whereHas('role', fn ($r) => $r->whereJsonContains('permissions', 'leads.manage'))
            ->limit(500)->get()->each(fn (User $u) => StaffAvailability::ensureExists($company->id, $u->id));

        $candidate = StaffAvailability::query()
            ->where('company_id', $company->id)
            ->where('is_available', true)
            ->whereNotIn('status', ['offline', 'busy'])
            ->when($excludeStaffIds, fn ($q) => $q->whereNotIn('staff_id', $excludeStaffIds))
            ->when($onlyStaffIds, fn ($q) => $q->whereIn('staff_id', $onlyStaffIds))
            ->whereHas('staff', fn ($q) => $q->where('is_active', true))
            ->orderBy('today_leads_count')
            ->orderByRaw('last_seen_at IS NULL DESC')
            ->orderBy('last_seen_at')
            ->lockForUpdate()   // inside assign()'s transaction: a concurrent lead waits, then sees the bumped count and picks someone else
            ->first();

        return $candidate?->staff;
    }

    /**
     * Round robin over exactly the people asked for, whether or not they are marked online: active, able to open leads and under
     * their lead cap, fewest leads today first, then the one idle longest. Used when an admin picks the staff by hand.
     */
    private function pickIgnoringAvailability(Company $company, array $excludeStaffIds, array $onlyStaffIds): ?User
    {
        $users = User::where('company_id', $company->id)->where('is_active', true)
            ->when($excludeStaffIds, fn ($q) => $q->whereNotIn('id', $excludeStaffIds))
            ->when($onlyStaffIds, fn ($q) => $q->whereIn('id', $onlyStaffIds))
            ->with('role')->orderBy('id')->get()
            ->filter(fn (User $u) => $u->hasAnyPermission(['leads.view', 'leads.manage']));
        if ($users->isEmpty()) {
            return null;
        }

        foreach ($users as $u) { StaffAvailability::ensureExists($company->id, $u->id); }
        $rows = StaffAvailability::whereIn('staff_id', $users->pluck('id'))->lockForUpdate()->get()->keyBy('staff_id');   // a concurrent lead waits, then sees the bumped count

        return $users
            ->filter(fn (User $u) => !($u->max_leads > 0 && (int) ($rows[$u->id]->current_leads_count ?? 0) >= $u->max_leads))
            ->sortBy(fn (User $u) => [(int) ($rows[$u->id]->today_leads_count ?? 0), $rows[$u->id]->last_seen_at?->getTimestamp() ?? 0, $u->id])
            ->first();
    }

    /**
     * Is "now" (in the rule timezone) inside the company's working hours?
     * Falls back to the rule's global start/end/days when no per-weekday rows
     * exist. A holiday override for the date always means closed.
     */
    public function withinWorkingHours(Company $company, LeadAssignmentRule $rule): bool
    {
        $tz = $rule->timezone ?: 'Asia/Kolkata';
        $now = Carbon::now($tz);

        if (CompanyHoliday::where('company_id', $company->id)->whereDate('date', $now->toDateString())->exists()) {
            return false;
        }

        $row = CompanyWorkingHour::where('company_id', $company->id)->where('weekday', (int) $now->dayOfWeek)->first();

        if ($row) {
            if (!$row->is_open) {
                return false;
            }
            $start = $now->copy()->setTimeFromTimeString((string) $row->start_time);
            $end   = $now->copy()->setTimeFromTimeString((string) $row->end_time);
        } else {
            $openDays = $rule->working_days ?: [1, 2, 3, 4, 5];
            if (!in_array((int) $now->dayOfWeek, $openDays, true)) {
                return false;
            }
            $start = $now->copy()->setTimeFromTimeString((string) ($rule->working_hours_start ?: '09:00:00'));
            $end   = $now->copy()->setTimeFromTimeString((string) ($rule->working_hours_end ?: '18:00:00'));
        }

        return $now->betweenIncluded($start, $end);
    }

    public function calculatePriority(Contact $contact, array $dupCheck): int
    {
        $priority = 5;
        $score = $contact->lead_score ?? 0;

        if ($score > 75) $priority = 1;
        elseif ($score > 50) $priority = 3;

        if ($dupCheck['is_duplicate'] && $dupCheck['recommended_action'] === 'assign_same_staff') {
            $priority = min($priority, 2);
        }

        if ($contact->lead_stage === 'converted') {
            $priority = min($priority, 2);
        }

        return $priority;
    }
}
