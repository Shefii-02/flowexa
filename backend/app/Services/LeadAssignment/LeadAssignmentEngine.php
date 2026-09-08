<?php

namespace App\Services\LeadAssignment;

use App\Jobs\CheckLeadSla;
use App\Jobs\NotifyStaffNewLead;
use App\Jobs\SendLeadNotifications;
use App\Models\Company;
use App\Models\CompanyHoliday;
use App\Models\CompanyWorkingHour;
use App\Models\Contact;
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
    ) {}

    public function assign(
        Company $company,
        Contact $contact,
        string $sourceType = 'organic',
        ?int $campaignId = null,
        ?string $sourceRef = null,
        string $assignmentType = 'auto'
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
            $assignmentType, $rule, $dupCheck
        ) {
            $resolvedType = $rule->notification_mode === 'uber' ? 'notification' : $assignmentType;

            $assignment = LeadAssignment::create([
                'company_id'           => $company->id,
                'contact_id'           => $contact->id,
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

            // Uber-only mode → just send notifications
            if ($rule->notification_mode === 'uber') {
                dispatch(new SendLeadNotifications($assignment->id, $rule->id));
                return $assignment;
            }

            // Basic "round robin" strategy → assign to the least-recently-loaded
            // available staff member, one by one, ignoring the weighted algorithm.
            if (($rule->strategy ?? 'algorithm') === 'round_robin') {
                $staff = $this->roundRobinPick($company);
                if ($staff) {
                    $this->assignToStaff($assignment, $staff, $rule);
                } elseif ($rule->notification_mode === 'hybrid') {
                    dispatch(new SendLeadNotifications($assignment->id, $rule->id));
                } else {
                    $this->startAiAgent($assignment, $company, $contact);
                }
                return $assignment;
            }

            // Try auto-assign
            $excludeIds = [];

            // For same-staff duplicates, try previous staff first
            if ($dupCheck['is_duplicate'] && $dupCheck['recommended_action'] === 'assign_same_staff' && $dupCheck['previous_staff']) {
                $prevStaff = $dupCheck['previous_staff'];
                $prevAvail = StaffAvailability::ensureExists($company->id, $prevStaff->id);
                $prevScore = $this->scorer->score($prevStaff, $prevAvail, $rule, $dupCheck['previous_assignment']);
                if ($prevScore > 0) {
                    $this->assignToStaff($assignment, $prevStaff, $rule);
                    return $assignment;
                }
            }

            $ranked = $this->scorer->rankStaff($company, $rule, $dupCheck['previous_assignment'] ?? null, $excludeIds);

            if ($ranked->isEmpty()) {
                // Hybrid → fall back to notifications, else AI
                if ($rule->notification_mode === 'hybrid') {
                    dispatch(new SendLeadNotifications($assignment->id, $rule->id));
                } else {
                    $this->startAiAgent($assignment, $company, $contact);
                }
                return $assignment;
            }

            $this->assignToStaff($assignment, $ranked->first()->staff, $rule);
            return $assignment;
        });
    }

    public function assignToStaff(LeadAssignment $assignment, User $staff, LeadAssignmentRule $rule): void
    {
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

    /** Least-loaded available staff member, then longest-idle (round-robin). */
    public function roundRobinPick(Company $company): ?User
    {
        $candidate = StaffAvailability::query()
            ->where('company_id', $company->id)
            ->where('is_available', true)
            ->whereNotIn('status', ['offline', 'busy'])
            ->whereHas('staff', fn ($q) => $q->where('is_active', true))
            ->orderBy('today_leads_count')
            ->orderByRaw('last_seen_at IS NULL DESC')
            ->orderBy('last_seen_at')
            ->first();

        return $candidate?->staff;
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
