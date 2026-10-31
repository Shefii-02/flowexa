<?php

namespace App\Modules\Instagram\Services;

use App\Models\CrmDeal;
use App\Models\InstagramAccount;
use App\Models\InstagramConversation;
use App\Models\InstagramConversionEvent;
use App\Models\Lead;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Reports an Instagram conversation's business milestones back to Meta (Conversions API for business messaging,
 * permission instagram_manage_events), so a business running Click-to-Instagram-Direct ads can optimise for real
 * leads and sales instead of message volume. Only for accounts whose admin switched "Send conversion events to Meta"
 * on, and only the event types they chose.
 *
 *   Lead created from an Instagram DM contact  → LeadSubmitted
 *   CRM deal won                               → Purchase (deal value + currency)
 */
class InstagramConversionService
{
    public const EVENT_LEAD     = 'LeadSubmitted';
    public const EVENT_PURCHASE = 'Purchase';
    public const CHOICES        = ['lead', 'purchase'];

    /** The Instagram thread (and so account + IGSID) a CRM contact came from, if sending is on for it. */
    public function threadFor(int $companyId, ?int $contactId, string $choice): ?InstagramConversation
    {
        if (!$contactId) return null;
        $convo = InstagramConversation::where('company_id', $companyId)->where('contact_id', $contactId)
            ->orderByDesc('last_message_at')->first();
        $account = $convo ? InstagramAccount::find($convo->instagram_account_id) : null;

        return $account && $account->is_active && $account->send_conversion_events
            && in_array($choice, $account->conversion_events ?: self::CHOICES, true) ? $convo : null;
    }

    public function sendLead(Lead $lead): void
    {
        if ($lead->source !== 'instagram' && $lead->origin_type !== 'instagram_account') return;
        $convo = $this->threadFor($lead->company_id, $lead->contact_id, 'lead');
        if (!$convo) return;

        $this->send($convo, self::EVENT_LEAD, "lead-{$lead->id}", $lead->created_at, [], ['lead_id' => $lead->id]);
    }

    public function sendPurchase(CrmDeal $deal): void
    {
        $convo = $this->threadFor($deal->company_id, $deal->contact_id, 'purchase');
        if (!$convo) return;

        $custom = ['currency' => strtoupper($deal->currency ?: 'INR'), 'value' => (float) $deal->value];
        $this->send($convo, self::EVENT_PURCHASE, "purchase-{$deal->id}", $deal->closed_at ?? now(), $custom, ['deal_id' => $deal->id]);
    }

    /** Never throws: the outcome is written to instagram_conversion_events so the business can see it. */
    private function send(InstagramConversation $convo, string $name, string $eventId, $time, array $custom, array $refs): void
    {
        $account = InstagramAccount::find($convo->instagram_account_id);
        if (InstagramConversionEvent::where('instagram_account_id', $account->id)->where('event_id', $eventId)->where('status', 'sent')->exists()) return;

        $time = $time ? \Carbon\Carbon::parse($time) : now();
        $log  = ['company_id' => $account->company_id, 'instagram_account_id' => $account->id, 'event_id' => $eventId, 'event_name' => $name,
                 'value' => $custom['value'] ?? null, 'currency' => $custom['currency'] ?? null, 'event_time' => $time] + $refs;

        try {
            $dataset = $this->datasetId($account);
            $event = [
                'event_name'        => $name,
                'event_time'        => min($time->timestamp, now()->timestamp),
                'event_id'          => $eventId,
                'action_source'     => 'business_messaging',
                'messaging_channel' => 'instagram',
                'user_data'         => ['instagram_business_account_id' => $account->ig_user_id, 'ig_sid' => $convo->participant_id],
            ] + ($custom ? ['custom_data' => $custom] : []);

            $res = Http::timeout(20)->asJson()->post(
                $this->url("{$dataset}/events") . '?access_token=' . urlencode($account->access_token),
                ['data' => [$event], 'partner_agent' => 'teamzo']
            );
            if (!$res->successful()) {
                throw new \RuntimeException($res->json('error.message') ?: "Meta answered HTTP {$res->status()}");
            }
            $row = $log + ['status' => 'sent', 'error' => null];
        } catch (\Throwable $e) {
            Log::warning("[ig-conversions] {$name} {$eventId} failed: {$e->getMessage()}");
            $row = $log + ['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500)];
        }

        InstagramConversionEvent::updateOrCreate(['instagram_account_id' => $account->id, 'event_id' => $eventId], $row);
    }

    /** The Meta dataset that collects this IG account's events — Meta returns the existing one if there already is one. */
    private function datasetId(InstagramAccount $account): string
    {
        if ($account->conversion_dataset_id) return $account->conversion_dataset_id;

        $res = Http::timeout(20)->asForm()->post($this->url("{$account->ig_user_id}/dataset"), ['access_token' => $account->access_token]);
        $id = $res->json('id');
        if (!$res->successful() || !$id) {
            throw new \RuntimeException($res->json('error.message') ?: 'Could not get the events dataset for this Instagram account — check that the connection has the instagram_manage_events permission.');
        }
        $account->update(['conversion_dataset_id' => (string) $id]);
        return (string) $id;
    }

    private function url(string $path): string
    {
        return 'https://graph.facebook.com/' . config('services.instagram.events_graph_version', 'v23.0') . '/' . ltrim($path, '/');
    }
}
