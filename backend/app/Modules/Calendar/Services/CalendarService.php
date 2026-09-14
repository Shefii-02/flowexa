<?php

namespace App\Modules\Calendar\Services;

use App\Models\CalendarEvent;
use App\Models\Company;
use App\Models\CrmTask;
use App\Models\GoogleIntegration;
use App\Modules\Google\GoogleClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Books appointments/followups for a company. The local `calendar_events` row is always the
 * source of truth — a company can book without ever touching Google. When the company HAS a
 * Google connection (with the Calendar scope), every write is best-effort mirrored into their
 * real Google Calendar so staff see it in the calendar app they already use; a mirror failure
 * (missing scope, expired token) is recorded on the row but never blocks the booking itself.
 */
class CalendarService
{
    public function __construct(private readonly GoogleClient $google) {}

    /**
     * @param array{title:string, description?:?string, location?:?string, type?:string,
     *   starts_at:string, ends_at:string, timezone?:string, contact_id?:?int, lead_id?:?int,
     *   assigned_to?:?int, created_by?:?int, attendee_email?:?string, want_meet_link?:?bool,
     *   crm_task_id?:?int}
     */
    public function book(Company $company, array $data): CalendarEvent
    {
        $event = CalendarEvent::create([
            'company_id'  => $company->id,
            'contact_id'  => $data['contact_id'] ?? null,
            'lead_id'     => $data['lead_id'] ?? null,
            'crm_task_id' => $data['crm_task_id'] ?? null,
            'assigned_to' => $data['assigned_to'] ?? null,
            'created_by'  => $data['created_by'] ?? null,
            'title'       => $data['title'],
            'description' => $data['description'] ?? null,
            'location'    => $data['location'] ?? null,
            'type'        => $data['type'] ?? 'appointment',
            'status'      => 'scheduled',
            'starts_at'   => $data['starts_at'],
            'ends_at'     => $data['ends_at'],
            'timezone'    => $data['timezone'] ?? 'Asia/Kolkata',
        ]);

        $this->syncToGoogle($event, $data['attendee_email'] ?? null, $data['want_meet_link'] ?? false);

        return $event->fresh();
    }

    /** Best-effort CalendarEvent for a CrmTask that already carries a due_at (e.g. HumanHandoffService's callback booking) — never throws. */
    public function bookFromTask(Company $company, CrmTask $task, string $type = 'callback'): ?CalendarEvent
    {
        if (!$task->due_at) {
            return null;
        }
        try {
            return $this->book($company, [
                'title'       => $task->title,
                'description' => $task->description,
                'type'        => $type,
                'starts_at'   => $task->due_at->toIso8601String(),
                'ends_at'     => $task->due_at->copy()->addMinutes(30)->toIso8601String(),
                'contact_id'  => $task->contact_id,
                'assigned_to' => $task->assigned_to,
                'crm_task_id' => $task->id,
            ]);
        } catch (\Throwable) {
            return null; // the CrmTask + staff notification is the important part; calendar mirroring is a bonus
        }
    }

    public function reschedule(CalendarEvent $event, Carbon $startsAt, Carbon $endsAt): CalendarEvent
    {
        $event->update(['starts_at' => $startsAt, 'ends_at' => $endsAt]);

        if ($event->isSyncedToGoogle() && ($integration = $this->activeIntegration($event->company))) {
            try {
                $this->google->updateEvent($integration, $event->google_event_id, [
                    'title'       => $event->title,
                    'description' => $event->description,
                    'location'    => $event->location,
                    'starts_at'   => $startsAt->toIso8601String(),
                    'ends_at'     => $endsAt->toIso8601String(),
                    'timezone'    => $event->timezone,
                ]);
                $event->update(['google_sync_error' => null]);
            } catch (\Throwable $e) {
                $event->update(['google_sync_error' => $e->getMessage()]);
            }
        }

        return $event->fresh();
    }

    public function cancel(CalendarEvent $event): void
    {
        $event->update(['status' => 'cancelled']);

        if ($event->isSyncedToGoogle() && ($integration = $this->activeIntegration($event->company))) {
            try {
                $this->google->deleteEvent($integration, $event->google_event_id);
            } catch (\Throwable $e) {
                $event->update(['google_sync_error' => $e->getMessage()]);
            }
        }
    }

    /** Local conflict check — overlapping, still-scheduled events for the same staff member (or company-wide if $assignedTo is null). */
    public function hasConflict(Company $company, Carbon $startsAt, Carbon $endsAt, ?int $assignedTo = null, ?int $excludeId = null): bool
    {
        return CalendarEvent::where('company_id', $company->id)
            ->where('status', 'scheduled')
            ->when($assignedTo, fn ($q) => $q->where('assigned_to', $assignedTo))
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt)
            ->exists();
    }

    public function upcoming(Company $company, ?Carbon $from = null, ?Carbon $to = null): Collection
    {
        return CalendarEvent::where('company_id', $company->id)
            ->when($from, fn ($q) => $q->where('ends_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('starts_at', '<=', $to))
            ->orderBy('starts_at')
            ->with(['contact:id,name,phone', 'lead:id,name,phone', 'assignee:id,name'])
            ->get();
    }

    private function syncToGoogle(CalendarEvent $event, ?string $attendeeEmail, bool $wantMeetLink): void
    {
        $integration = $this->activeIntegration($event->company);
        if (!$integration) {
            return;
        }
        try {
            $result = $this->google->createEvent($integration, [
                'title'          => $event->title,
                'description'    => $event->description,
                'location'       => $event->location,
                'starts_at'      => $event->starts_at->toIso8601String(),
                'ends_at'        => $event->ends_at->toIso8601String(),
                'timezone'       => $event->timezone,
                'attendee_email' => $attendeeEmail,
                'want_meet_link' => $wantMeetLink,
            ]);
            $event->update([
                'google_event_id'   => $result['id'],
                'meet_link'         => $result['meet_link'],
                'google_sync_error' => null,
            ]);
        } catch (\Throwable $e) {
            // Booking already succeeded locally — record why the mirror failed and move on.
            $event->update(['google_sync_error' => $e->getMessage()]);
        }
    }

    private function activeIntegration(Company $company): ?GoogleIntegration
    {
        return GoogleIntegration::where('company_id', $company->id)->where('is_active', true)->first();
    }
}
