<?php

namespace App\Modules\WaChat\Services\Agent;

use App\Jobs\NotifyStaffAiHandoff;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CrmTask;
use App\Modules\Calendar\Services\CalendarService;
use App\Services\LeadAssignment\LeadAssignmentEngine;
use Illuminate\Support\Carbon;

/**
 * Creates a staff follow-up task (+ best-effort Calendar mirror) for a contact the AI agent
 * talked to — the same staff-routing (round robin) HumanHandoffService already uses, so every
 * AI-created task lands on a real person's plate the same way regardless of which flow made it.
 * Two callers, per the "day/time-based follow-up" the AI agent supports:
 *   - AgentTurnPlanner detects the customer explicitly asking to be followed up later
 *     ("call me Monday", "remind me next week") — $dayHint carries what they said.
 *   - ConversationalAgentService's completeQualification() falls back to this as a safety net
 *     whenever a lead is qualified but the conversation didn't end in an actual booking/sale —
 *     $dayHint is null, which resolves to exactly one week out.
 */
class FollowupTaskScheduler
{
    private const WEEKDAYS = [
        'sunday' => 0, 'monday' => 1, 'tuesday' => 2, 'wednesday' => 3,
        'thursday' => 4, 'friday' => 5, 'saturday' => 6,
    ];

    public function __construct(
        private readonly LeadAssignmentEngine $assignmentEngine,
        private readonly CalendarService $calendar,
    ) {}

    public function schedule(Company $company, Contact $contact, string $title, string $note, ?string $dayHint = null): CrmTask
    {
        $dueAt = $this->resolveDate($dayHint);
        $staff = $this->assignmentEngine->roundRobinPick($company);

        $task = CrmTask::create([
            'company_id'  => $company->id,
            'contact_id'  => $contact->id,
            'assigned_to' => $staff?->id,
            'title'       => $title,
            'description' => $note,
            'type'        => 'call',
            'priority'    => 'medium',
            'status'      => 'open',
            'due_at'      => $dueAt,
        ]);

        if ($staff) {
            dispatch(new NotifyStaffAiHandoff($task->id, $staff->id));
        }

        // Best-effort — the task itself is the important part, calendar mirroring is a bonus.
        try {
            $this->calendar->bookFromTask($company, $task, 'followup');
        } catch (\Throwable) {
            // ignore
        }

        return $task;
    }

    /**
     * $hint is whatever the customer said mapped to: null/empty ("next week" generically),
     * "today", "tomorrow", or a weekday name. Anything else falls back to a week out, same
     * as no hint at all — a wrong guess here should never crash the conversation.
     */
    private function resolveDate(?string $hint): Carbon
    {
        $hint = strtolower(trim((string) $hint));
        $now  = now();

        if ($hint === 'today') {
            return $now->copy()->addHours(2);
        }
        if ($hint === 'tomorrow') {
            return $now->copy()->addDay()->setTime(11, 0);
        }
        if (isset(self::WEEKDAYS[$hint])) {
            $target = self::WEEKDAYS[$hint];
            $date   = $now->copy();
            do {
                $date->addDay();
            } while ((int) $date->dayOfWeek !== $target);
            return $date->setTime(11, 0);
        }

        // Unspecified / "next week" generically — same weekday, one week out.
        return $now->copy()->addWeek()->setTime(11, 0);
    }
}
