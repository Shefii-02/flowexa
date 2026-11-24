<?php

namespace App\Modules\Lead\Services;

use App\Models\HeldLead;
use App\Models\Lead;
use App\Modules\Lead\DTOs\CreateLeadDTO;
use App\Modules\Lead\Exceptions\LeadException;

/** Moves what a switched-off source captured into Leads (the normal lead path, so auto-assign still applies) — or discards it. */
class HeldLeadService
{
    public function __construct(private readonly LeadService $leads) {}

    /** @return array{status:string, lead_id:?int, note:?string} */
    public function move(HeldLead $h, int $userId, ?int $assignTo = null): array
    {
        if ($h->status !== 'held') {
            return ['status' => $h->status, 'lead_id' => $h->lead_id, 'note' => 'Already handled.'];
        }
        try {
            $lead = $this->leads->create($h->company_id, new CreateLeadDTO(
                contactId: (int) $h->contact_id, source: $h->source, category: $h->category, priority: $h->priority ?: 'medium', notes: $h->notes,
                flowNodeId: $h->flow_node_id, campaignId: $h->campaign_id, originType: $h->origin_type, originId: $h->origin_id, originLabel: $h->origin_label,
            ));
            if ($h->wa_open_campaign_id) {
                Lead::whereKey($lead->id)->update(['wa_open_campaign_id' => $h->wa_open_campaign_id]);
            }
            if ($assignTo) {
                // the reviewer picked who gets it — same checks as any assignment (the person must belong to this company)
                $who = \App\Models\User::where('company_id', $h->company_id)->where('is_active', true)->find($assignTo);
                if ($who) { $lead = app(\App\Modules\Lead\Repositories\LeadRepository::class)->assign($lead, $who->id, $userId); }
            }
            if ($h->held_reason === 'needs_review') {
                // the lead's own log shows who let it in — and what it was seen as
                \App\Models\LeadEvent::create(['lead_id' => $lead->id, 'company_id' => $h->company_id, 'user_id' => $userId, 'event' => 'lead_review_approved', 'payload' => [
                    'by' => \App\Models\User::whereKey($userId)->value('name'), 'topic' => $h->topic, 'held_at' => $h->first_captured_at?->toIso8601String(), 'hits' => $h->hits,
                    'assigned_to' => $assignTo ? \App\Models\User::whereKey($assignTo)->value('name') : null,
                ]]);
            }
            $this->finish($h, 'moved', $lead->id, $userId, null);
            return ['status' => 'moved', 'lead_id' => $lead->id, 'note' => null];
        } catch (LeadException $e) {
            // the contact already has an open lead (it may have been created by hand meanwhile) — nothing to move
            $open = Lead::where('company_id', $h->company_id)->where('contact_id', $h->contact_id)->whereNotIn('stage', ['enrolled', 'lost'])->latest()->value('id');
            $this->finish($h, 'discarded', $open, $userId, 'This contact already has an open lead.');
            return ['status' => 'duplicate', 'lead_id' => $open, 'note' => 'Already has an open lead.'];
        }
    }

    public function discard(HeldLead $h, int $userId, ?string $note = null): void
    {
        if ($h->status === 'held') {
            $this->finish($h, 'discarded', null, $userId, $note ?: 'Discarded by staff.');
        }
    }

    private function finish(HeldLead $h, string $status, ?int $leadId, int $userId, ?string $note): void
    {
        $h->forceFill(['status' => $status, 'lead_id' => $leadId, 'handled_by' => $userId, 'handled_at' => now(), 'result_note' => $note])->save();
    }
}
