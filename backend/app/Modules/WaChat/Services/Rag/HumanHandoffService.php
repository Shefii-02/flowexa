<?php

namespace App\Modules\WaChat\Services\Rag;

use App\Jobs\NotifyStaffAiHandoff;
use App\Models\Company;
use App\Models\CompanyHoliday;
use App\Models\CompanyWorkingHour;
use App\Models\Contact;
use App\Models\CrmTask;
use App\Models\LeadAssignmentRule;
use App\Services\LeadAssignment\LeadAssignmentEngine;
use Illuminate\Support\Carbon;

/**
 * Fires when the AI genuinely fails to answer — ResponseGenerator's provider-call failure
 * (the "I'm sorry, I couldn't find an answer... a human agent will assist you shortly" case),
 * not just "nothing relevant in the knowledge base". That message promises a human will help;
 * this is what actually makes that true instead of it being an empty promise:
 *
 *   - During office hours: creates a CrmTask for a staff member and notifies them live,
 *     the same real-time channel the existing lead-notification popups use.
 *   - Outside office hours: staff genuinely aren't available, so instead of silently
 *     dropping the customer, they're offered a scheduled callback at the next few real
 *     office-hour slots — picking one creates a CrmTask due at that time.
 *
 * Deliberately builds a lightweight CrmTask rather than going through the full
 * LeadAssignmentEngine::assign() lead-intake lifecycle (SLA timers, availability counters,
 * accept/decline cascades) — this is "the AI hiccupped mid-conversation", not a new lead
 * coming in, and forcing it through that heavier machinery would have side effects (like
 * counting against a staff member's max_leads) that don't belong to this event.
 */
class HumanHandoffService
{
    public function __construct(private readonly LeadAssignmentEngine $assignmentEngine) {}

    /**
     * @param string[] &$offeredSlots Set to the ISO-8601 datetimes shown to the customer, in
     *   order, only when a callback offer is actually returned — the caller must remember
     *   this (e.g. on the AiAgentSession) so confirmCallback() can resolve the customer's
     *   reply against the same list they were actually shown.
     * @return string|null A callback-scheduling message to show the customer (outside office
     *   hours), or null when staff were notified live and the existing fallback text already
     *   covers it (or there's no working-hours schedule configured at all to offer against).
     */
    public function escalate(Company $company, string $phone, ?string $waSessionId = null, array &$offeredSlots = []): ?string
    {
        $rule = LeadAssignmentRule::where('company_id', $company->id)->first()
            ?? LeadAssignmentRule::create(LeadAssignmentRule::defaultForCompany($company->id));

        $contact = Contact::firstOrCreate(
            ['company_id' => $company->id, 'phone' => $phone],
            ['name' => $phone, 'source' => $waSessionId ? 'wa_chat' : 'whatsapp_cloud']
        );

        if ($this->assignmentEngine->withinWorkingHours($company, $rule)) {
            $this->createTaskAndNotify(
                $company, $contact, $phone, now(),
                'AI could not answer a customer question',
                "The AI agent's provider call failed while answering this customer — please follow up."
            );
            return null;
        }

        $slots = $this->nextOfficeHourSlots($company, $rule);
        if (empty($slots)) {
            return null;
        }

        $lines = [];
        foreach ($slots as $i => $slot) {
            $lines[] = ($i + 1) . ') ' . $slot->format('l, M j \a\t g:i A');
        }
        $offeredSlots = array_map(fn ($s) => $s->toIso8601String(), $slots);

        return "We're currently outside office hours, so our team isn't available right this moment. "
            . "Would you like to schedule a callback instead? Just reply with a number:\n"
            . implode("\n", $lines);
    }

    /**
     * Resolves a reply to a previously-offered callback slot (see escalate()'s returned
     * message) into a real scheduled CrmTask. $offeredSlots must be the exact list this
     * customer was shown, in order — the caller is responsible for remembering it (e.g. on
     * the AiAgentSession) between the offer and this reply.
     *
     * @param string[] $offeredSlots ISO-8601 datetime strings, in the order shown to the customer
     * @return string|null The booking confirmation, or null if $reply isn't a valid slot pick
     *   (the caller should then fall through to handling the message normally).
     */
    public function confirmCallback(Company $company, string $phone, string $reply, array $offeredSlots): ?string
    {
        $choice = (int) trim($reply);
        if ($choice < 1 || $choice > count($offeredSlots)) {
            return null;
        }

        $slot    = Carbon::parse($offeredSlots[$choice - 1]);
        $contact = Contact::where('company_id', $company->id)->where('phone', $phone)->first();

        $this->createTaskAndNotify(
            $company, $contact, $phone, $slot,
            'Scheduled callback' . ($contact?->name && $contact->name !== $phone ? " — {$contact->name}" : ''),
            "Customer asked a question our AI agent couldn't answer and requested a callback "
                . "since it was outside office hours. Phone: {$phone}."
        );

        return "You're booked for {$slot->format('l, M j \a\t g:i A')} — one of our team will call you then. Thanks for your patience!";
    }

    private function createTaskAndNotify(
        Company $company, ?Contact $contact, string $phone, Carbon $dueAt, string $title, string $description
    ): void {
        $staff = $this->assignmentEngine->roundRobinPick($company);

        $task = CrmTask::create([
            'company_id'  => $company->id,
            'contact_id'  => $contact?->id,
            'assigned_to' => $staff?->id,
            'title'       => $title,
            'description' => $description,
            'type'        => 'call',
            'priority'    => 'high',
            'status'      => 'open',
            'due_at'      => $dueAt,
        ]);

        if ($staff) {
            dispatch(new NotifyStaffAiHandoff($task->id, $staff->id));
        }
    }

    /** Next $count valid office-hour start times, walking forward day by day (skips holidays/closed days). */
    private function nextOfficeHourSlots(Company $company, LeadAssignmentRule $rule, int $count = 3): array
    {
        $tz    = $rule->timezone ?: 'Asia/Kolkata';
        $now   = Carbon::now($tz);
        $slots = [];

        for ($daysAhead = 0; $daysAhead < 14 && count($slots) < $count; $daysAhead++) {
            $day = $now->copy()->addDays($daysAhead)->startOfDay();

            if (CompanyHoliday::where('company_id', $company->id)->whereDate('date', $day->toDateString())->exists()) {
                continue;
            }

            $row = CompanyWorkingHour::where('company_id', $company->id)->where('weekday', (int) $day->dayOfWeek)->first();

            if ($row) {
                if (!$row->is_open) {
                    continue;
                }
                $start = $day->copy()->setTimeFromTimeString((string) $row->start_time);
            } else {
                $openDays = $rule->working_days ?: [1, 2, 3, 4, 5];
                if (!in_array((int) $day->dayOfWeek, $openDays, true)) {
                    continue;
                }
                $start = $day->copy()->setTimeFromTimeString((string) ($rule->working_hours_start ?: '09:00:00'));
            }

            if ($start->lessThan($now)) {
                continue; // a slot earlier today that's already passed
            }

            $slots[] = $start;
        }

        return $slots;
    }
}
