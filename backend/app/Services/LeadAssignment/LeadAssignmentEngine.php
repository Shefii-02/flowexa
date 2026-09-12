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
use App\Models\StaffAvailability;
use App\Models\User;
use Illuminate\Support\Carbon;
use App\Modules\WaChat\Models\AiAgentSession;
use Illuminate\Support\Facades\DB;

class LeadAssignmentEngine
{
    public function __construct(
        private readonly StaffScorer $scorer,
        private readonly DuplicateLeadDetector $detector,
        private readonly LeadActivityLogger $activity,
    ) {}

    public function assign(
        Company $company,
        Contact $contact,
        string $sourceType = 'organic',
        ?int $campaignId = null,
        ?string $sourceRef = null,
        string $assignmentType = 'auto',
        ?Lead $lead = null,
    ): LeadAssignment {
        $rule = LeadAssignmentRule::where('company_id', $company->id)->first()
            ?? LeadAssignmentRule::create(LeadAssignmentRule::defaultForCompany($company->id));

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
            $assignmentType, $rule, $dupCheck, $lead
        ) {
            $resolvedType = $rule->notification_mode === 'uber' ? 'notification' : $assignmentType;

            $assignment = LeadAssignment::create([
                'company_id'           => $company->id,
                'contact_id'           => $contact->id,
                'lead_id'              => $lead?->id,
                'campaign_id'          => $campaignId,
                'source_type'          => $sourceType,
                'source_ref'           => $sourceRef,
                'status'               => 'pending',
                'assignment_type'      => $resolvedType,
                'priority'             => $this->calculatePriority($contact, $dupCheck),
                'response_sla_minutes' => $rule->sla_minutes,
                'notes'                => $dupCheck['is_duplicate'] ? $dupCheck['reason'] : null,
            ]);

            $updatePayload = [
                'current_assignment_id' => $assignment->id,
                'last_lead_at'          => now(),
                'total_leads_count'     => DB::raw('total_leads_count + 1'),
            ];
            if (!$contact->first_lead_at) {
                $updatePayload['first_lead_at'] = now();
            }
            $contact->update($updatePayload);

            // Outside the company's working hours (per-weekday schedule + holiday
            // overrides) → hand straight to the AI agent so the lead isn't dropped.
            if (!$this->withinWorkingHours($company, $rule)) {
                $this->startAiAgent($assignment, $company, $contact);
                $assignment->update(['transfer_reason' => 'Received outside working hours — AI agent engaged.']);
                return $assignment;
            }

            // Same-contact duplicate whose rule says "keep the same staff" → route straight back
            // to them (skipping the notify ceremony) as long as they can still take it, for both
            // notification modes below.
            if ($dupCheck['is_duplicate'] && $dupCheck['recommended_action'] === 'assign_same_staff' && $dupCheck['previous_staff']) {
                $prevStaff = $dupCheck['previous_staff'];
                $prevAvail = StaffAvailability::ensureExists($company->id, $prevStaff->id);
                $prevScore = $this->scorer->score($prevStaff, $prevAvail, $rule, $dupCheck['previous_assignment']);
                if ($prevScore > 0) {
                    $this->assignToStaff($assignment, $prevStaff, $rule);
                    return $assignment;
                }
            }

            // notification_mode "auto" is the only mode that skips the accept/reject ceremony — it
            // instant-assigns via whichever strategy the rule uses (round robin or the weighted
            // algorithm), falling back to the AI agent only if nobody is available at all.
            if ($rule->notification_mode === 'auto') {
                $staff = ($rule->strategy ?? 'algorithm') === 'round_robin'
                    ? $this->roundRobinPick($company)
                    : ($this->scorer->rankStaff($company, $rule, $dupCheck['previous_assignment'] ?? null)->first()->staff ?? null);

                if ($staff) {
                    $this->assignToStaff($assignment, $staff, $rule);
                } else {
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

    public function assignToStaff(LeadAssignment $assignment, User $staff, LeadAssignmentRule $rule): void
    {
        $wasTransfer = $assignment->staff_id && $assignment->staff_id !== $staff->id && $assignment->exists;

        $assignment->update([
            'staff_id'    => $staff->id,
            'status'      => 'assigned',
            'accepted_at' => now(),
        ]);

        $availability = StaffAvailability::ensureExists($assignment->company_id, $staff->id);
        $availability->increment('current_leads_count');
        $availability->increment('today_leads_count');

        // Mark busy if at max_leads
        if ($staff->max_leads > 0 && $availability->fresh()->current_leads_count >= $staff->max_leads) {
            $availability->update(['status' => 'busy', 'is_available' => false]);
        }

        $this->activity->syncAssignee($assignment, $staff->id);
        if (!$wasTransfer) {
            $this->activity->log($assignment, 'lead_assigned', ['staff_id' => $staff->id, 'staff_name' => $staff->name]);
        }

        dispatch(new NotifyStaffNewLead($assignment->id, $staff->id));

        dispatch(new CheckLeadSla($assignment->id))
            ->delay(now()->addMinutes($assignment->response_sla_minutes));
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
    public function roundRobinPick(Company $company, array $excludeStaffIds = []): ?User
    {
        $candidate = StaffAvailability::query()
            ->where('company_id', $company->id)
            ->where('is_available', true)
            ->whereNotIn('status', ['offline', 'busy'])
            ->when($excludeStaffIds, fn ($q) => $q->whereNotIn('staff_id', $excludeStaffIds))
            ->whereHas('staff', fn ($q) => $q->where('is_active', true))
            ->orderBy('today_leads_count')
            ->orderByRaw('last_seen_at IS NULL DESC')
            ->orderBy('last_seen_at')
            ->first();

        return $candidate?->staff;
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
