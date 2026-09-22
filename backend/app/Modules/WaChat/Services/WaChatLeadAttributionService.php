<?php

namespace App\Modules\WaChat\Services;

use App\Models\CampaignContact;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\WaPhoneNumber;
use App\Modules\WaChat\Models\WahaMessageLog;
use App\Modules\WaChat\Models\WahaSession;
use Illuminate\Support\Carbon;

// Runs on every inbound WhatsApp message — open-wa (WahaSessionController::webhook)
// and WA Cloud (WebhookService::handleInbound) alike — independent of whether the
// company has an AI playbook configured. ConversationalAgentService's own lead
// creation only fires when one exists (see its ensureContactAndLead()), so this is
// the one place that guarantees a lead exists for every WhatsApp conversation.
// Idempotent by design (firstOrCreate + an "already has an open lead" check before
// creating), so it's harmless if it also runs there for the same message.
class WaChatLeadAttributionService
{
    // ── open-wa: origin = which WA session received it, campaign = message_sender_jobs ──
    /** @return bool true if this reply resulted in a new lead being created. */
    public function handleInboundMessage(int $companyId, string $sessionRef, string $phone, ?Carbon $receivedAt = null): bool
    {
        return $this->attribute(
            companyId:      $companyId,
            phone:          $phone,
            receivedAt:     $receivedAt ?? now(),
            source:         'wa_chat',
            campaignField:  'wa_open_campaign_id',
            resolveOrigin:  fn() => $this->resolveWaSessionOrigin($companyId, $sessionRef),
            resolveCampaign: fn(Carbon $at) => $this->matchOpenWaCampaign($companyId, $phone, $at),
        );
    }

    // ── WA Cloud: origin = which of the company's phone numbers received it, campaign
    // = the existing Modules/Campaign `campaigns` table (leads.campaign_id already exists) ──
    /** @return bool true if this reply resulted in a new lead being created. */
    public function handleInboundMetaCloudMessage(int $companyId, string $phoneNumberId, string $phone, ?Carbon $receivedAt = null): bool
    {
        return $this->attribute(
            companyId:      $companyId,
            phone:          $phone,
            receivedAt:     $receivedAt ?? now(),
            source:         'whatsapp_cloud',
            campaignField:  'campaign_id',
            resolveOrigin:  fn() => $this->resolvePhoneNumberOrigin($companyId, $phoneNumberId),
            resolveCampaign: fn(Carbon $at) => $this->matchWaCloudCampaign($companyId, $phone, $at),
        );
    }

    private function attribute(
        int $companyId,
        string $phone,
        Carbon $receivedAt,
        string $source,
        string $campaignField,
        \Closure $resolveOrigin,
        \Closure $resolveCampaign,
    ): bool {
        if ($phone === '') {
            return false;
        }

        $contact = Contact::firstOrCreate(
            ['company_id' => $companyId, 'phone' => $phone],
            ['name' => $phone, 'source' => $source],
        );

        // A lead already open for this contact (not enrolled/lost) means this reply is
        // a continuation of an existing conversation — nothing new to create. A contact
        // whose only leads are already closed gets a fresh one below: re-engaging after
        // conversion is a new conversation, not a reopening of the old one.
        $hasOpenLead = Lead::where('company_id', $companyId)
            ->where('contact_id', $contact->id)
            ->whereNotIn('stage', ['enrolled', 'lost', 'disqualified'])
            ->exists();

        if ($hasOpenLead) {
            return false;
        }

        [$originType, $originId, $originLabel] = $resolveOrigin();
        $campaignId = $resolveCampaign($receivedAt);

        Lead::create([
            'company_id'   => $companyId,
            'contact_id'   => $contact->id,
            'stage'        => 'new',
            'source'       => $source,
            'origin_type'  => $originType,
            'origin_id'    => $originId,
            'origin_label' => $originLabel,
            $campaignField => $campaignId,
            'notes'        => $campaignId
                ? "Auto-created from a reply to campaign #{$campaignId}."
                : 'Auto-created on first WhatsApp message.',
        ]);

        return true;
    }

    /** @return array{0:?string,1:?int,2:?string} [originType, originId, originLabel] */
    private function resolveWaSessionOrigin(int $companyId, string $sessionRef): array
    {
        $session = WahaSession::where('company_id', $companyId)->where('session_name', $sessionRef)->first();
        if (!$session) {
            return [null, null, null];
        }
        return ['wa_session', $session->id, $session->display_name ?: $session->session_name];
    }

    /** @return array{0:?string,1:?int,2:?string} [originType, originId, originLabel] */
    private function resolvePhoneNumberOrigin(int $companyId, string $phoneNumberId): array
    {
        $number = WaPhoneNumber::where('company_id', $companyId)->where('phone_number_id', $phoneNumberId)->first();
        if (!$number) {
            return [null, null, null];
        }
        return ['phone_number', $number->id, $number->label ?: $number->display_number];
    }

    // Attributes the reply to the most recently-sent campaign that (a) actually messaged
    // this phone number and (b) still has its lead_created_from/lead_created_to window
    // — the same From/To range picked in message-sender's "Also add leads by date"
    // option — covering the day the reply came in.
    private function matchOpenWaCampaign(int $companyId, string $phone, Carbon $receivedAt): ?int
    {
        $normalizedPhone = preg_replace('/\D/', '', $phone);
        if (!$normalizedPhone) {
            return null;
        }
        $today = $receivedAt->toDateString();

        $log = WahaMessageLog::where('company_id', $companyId)
            ->where('status', 'sent')
            ->whereNotNull('job_id')
            ->whereHas('job', fn($q) => $q
                ->whereNotNull('lead_created_from')
                ->whereNotNull('lead_created_to')
                ->whereDate('lead_created_from', '<=', $today)
                ->whereDate('lead_created_to', '>=', $today))
            ->orderByDesc('sent_at')
            ->get()
            ->first(fn($l) => preg_replace('/\D/', '', $l->recipient_phone) === $normalizedPhone);

        return $log?->job_id;
    }

    // Same idea for WA Cloud: attributes the reply to the most recently-sent campaign
    // (Modules/Campaign) that messaged this phone and whose starts_at/ends_at run-time
    // window still covers the moment the reply came in.
    private function matchWaCloudCampaign(int $companyId, string $phone, Carbon $receivedAt): ?int
    {
        $normalizedPhone = preg_replace('/\D/', '', $phone);
        if (!$normalizedPhone) {
            return null;
        }

        $contact = CampaignContact::where('status', 'sent')
            ->whereHas('campaign', fn($q) => $q
                ->where('company_id', $companyId)
                ->whereNotNull('starts_at')
                ->whereNotNull('ends_at')
                ->where('starts_at', '<=', $receivedAt)
                ->where('ends_at', '>=', $receivedAt))
            ->orderByDesc('sent_at')
            ->get()
            ->first(fn($c) => preg_replace('/\D/', '', $c->phone) === $normalizedPhone);

        return $contact?->campaign_id;
    }
}
