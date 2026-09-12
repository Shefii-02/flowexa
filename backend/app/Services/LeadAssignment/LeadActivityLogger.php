<?php

namespace App\Services\LeadAssignment;

use App\Models\Lead;
use App\Models\LeadAssignment;
use App\Models\LeadEvent;

/**
 * Bridges the routing/notification engine (which works off Contact, not Lead) back onto the CRM
 * Lead it routed: every state change — assigned, accepted, declined, timed out and reassigned,
 * transferred, handed to AI, completed — lands as a LeadEvent on that lead's own activity
 * timeline, and Lead.assigned_to stays in sync with whoever is actually holding it right now.
 * A no-op when the assignment isn't linked to a Lead (e.g. one created via the raw
 * lead-assignments API without a CRM lead behind it).
 */
class LeadActivityLogger
{
    public function log(LeadAssignment $assignment, string $event, array $payload = [], ?int $userId = null): void
    {
        if (!$assignment->lead_id) {
            return;
        }
        LeadEvent::create([
            'lead_id'    => $assignment->lead_id,
            'company_id' => $assignment->company_id,
            'user_id'    => $userId,
            'event'      => $event,
            'payload'    => $payload,
        ]);
    }

    public function syncAssignee(LeadAssignment $assignment, ?int $staffId): void
    {
        if (!$assignment->lead_id) {
            return;
        }
        Lead::where('id', $assignment->lead_id)->update([
            'assigned_to' => $staffId,
            'assigned_at' => $staffId ? now() : null,
        ]);
    }
}
