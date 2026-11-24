<?php

namespace App\Observers;

use App\Jobs\NotifyLeadEvent;
use App\Models\Lead;
use App\Models\User;

/**
 * Tells people when a lead moves: assigned to them, transferred to / away from them, taken back — and the team heads when a new lead
 * is still nobody's after a minute. Fires for changes made through the model (manual assign, bulk switch, recall, transfer); the
 * auto-assign engine writes the owner directly and sends its own push (NotifyStaffNewLead), so nothing is notified twice.
 * Whoever made the change is never notified about their own action.
 */
class LeadObserver
{
    public bool $afterCommit = true;

    public function created(Lead $lead): void
    {
        if ($lead->assigned_to) {
            $this->tell('assigned', $lead, (int) $lead->assigned_to);
        } else {
            NotifyLeadEvent::dispatch('new_waiting', $lead->id)->delay(now()->addSeconds(90));   // still unassigned after the engine had its go → heads
        }
    }

    public function updated(Lead $lead): void
    {
        if (!$lead->wasChanged('assigned_to')) { return; }
        $old = $lead->getOriginal('assigned_to') ? (int) $lead->getOriginal('assigned_to') : null;
        $new = $lead->assigned_to ? (int) $lead->assigned_to : null;

        if ($new && !$old) {
            $this->tell('assigned', $lead, $new);
        } elseif ($new && $old && $new !== $old) {
            $this->tell('transferred_in', $lead, $new, ['from' => User::whereKey($old)->value('name')]);
            $this->tell('transferred_out', $lead, $old, ['to' => User::whereKey($new)->value('name')]);
        } elseif (!$new && $old) {
            $this->tell('recalled', $lead, $old);
        }
    }

    private function tell(string $kind, Lead $lead, int $userId, array $extra = []): void
    {
        if ($userId === (int) auth()->id()) { return; }   // you know what you just did
        NotifyLeadEvent::dispatch($kind, $lead->id, $userId, $extra);
    }
}
