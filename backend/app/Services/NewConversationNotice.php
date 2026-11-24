<?php

namespace App\Services;

use App\Jobs\NotifyLeadEvent;
use App\Models\Lead;

/**
 * A customer who ALREADY has an open lead starts a new conversation (WhatsApp Cloud / Instagram / website chat) → their lead's owner is told.
 * A brand-new customer is not announced here — that is a "new lead" and has its own notice.
 */
class NewConversationNotice
{
    public static function fire(?int $companyId, ?int $contactId, string $channel, ?int $inboxOwner = null): void
    {
        if (!$companyId || !$contactId) { return; }
        try {
            $lead = Lead::where('company_id', $companyId)->where('contact_id', $contactId)->whereNotIn('stage', ['enrolled', 'lost'])->latest('id')->first();
            if (!$lead || $lead->created_at?->gt(now()->subMinutes(2))) { return; }   // no lead yet / a brand-new lead (its own notice)
            $owner = (int) ($lead->assigned_to ?: $inboxOwner);
            if ($owner > 0 && $owner !== (int) auth()->id()) {
                NotifyLeadEvent::dispatch('conversation', $lead->id, $owner, ['channel' => $channel]);
            }
        } catch (\Throwable) {
            // a notice must never break the incoming message
        }
    }
}
