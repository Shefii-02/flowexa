<?php

namespace App\Modules\Instagram\Services;

use App\Models\InstagramAccount;
use App\Models\InstagramConversation;
use App\Models\InstagramMessage;
use Illuminate\Support\Facades\Log;

/**
 * Owns the DM thread + message store, and the send path (window check → API → persist).
 */
class InstagramConversationService
{
    public function __construct(private readonly InstagramClient $client) {}

    /** Find or open the thread for a participant. */
    public function thread(InstagramAccount $account, string $participantId, ?string $username = null): InstagramConversation
    {
        $convo = InstagramConversation::firstOrCreate(
            ['instagram_account_id' => $account->id, 'participant_id' => $participantId],
            [
                'company_id'           => $account->company_id,
                'participant_username' => $username,
                'ai_enabled'           => $account->ai_enabled,
                'status'               => 'open',
            ]
        );
        if ($username && $convo->participant_username !== $username) {
            $convo->update(['participant_username' => $username]);
        }
        return $convo;
    }

    /** Record an inbound DM from the messaging webhook. Returns the thread. */
    public function recordInbound(
        InstagramAccount $account,
        string $participantId,
        string $text,
        ?string $igMessageId = null,
        array $attachments = [],
        ?string $username = null,
    ): InstagramConversation {
        $convo = $this->thread($account, $participantId, $username);

        // Idempotency: Meta re-delivers webhooks.
        if ($igMessageId && $convo->messages()->where('ig_message_id', $igMessageId)->exists()) {
            return $convo;
        }

        InstagramMessage::create([
            'instagram_conversation_id' => $convo->id,
            'ig_message_id' => $igMessageId,
            'direction'     => 'in',
            'source'        => 'inbound',
            'text'          => $text,
            'attachments'   => $attachments ?: null,
            'sent_at'       => now(),
        ]);

        $convo->forceFill([
            'last_message_preview' => mb_substr($text ?: '[attachment]', 0, 140),
            'last_message_at'      => now(),
            'last_inbound_at'      => now(),
            'unread_count'         => $convo->unread_count + 1,
            'status'               => $convo->status === 'closed' ? 'open' : $convo->status,
        ])->save();

        return $convo;
    }

    /**
     * Send a DM in a thread and persist it. Respects the 24h messaging window unless $humanAgent.
     * Returns the created message, or null when the window is closed / the send failed.
     */
    public function send(InstagramConversation $convo, string $text, string $source = 'manual', bool $humanAgent = false): ?InstagramMessage
    {
        $account = $convo->account;

        // Inside the 24h window a normal message is enough. Past it, only a staff reply may use the
        // HUMAN_AGENT tag (up to 7 days) — and only once Meta has approved that feature for the app.
        $withinWindow = $convo->withinMessagingWindow();
        $useTag = $humanAgent && !$withinWindow && config('services.instagram.human_agent_enabled');
        if (!$withinWindow && !$useTag) {
            Log::info('InstagramConversationService: outside 24h window, not sending', ['conversation' => $convo->id]);
            return $this->storeOutbound($convo, $text, $source, null, 'failed', $humanAgent
                ? 'Outside the 24-hour messaging window. Replies after 24 hours need Meta\'s "Human Agent" approval, which isn\'t enabled for this app yet.'
                : 'Outside the 24-hour messaging window');
        }

        try {
            $res = $this->client->sendDm($account, $convo->participant_id, $text, $useTag);
            $msg = $this->storeOutbound($convo, $text, $source, $res['message_id'] ?? null, 'sent', null);
            $convo->update(['status' => 'open']);
            return $msg;
        } catch (\Throwable $e) {
            Log::warning('InstagramConversationService: send failed', ['conversation' => $convo->id, 'error' => $e->getMessage()]);
            return $this->storeOutbound($convo, $text, $source, null, 'failed', $e->getMessage());
        }
    }

    /**
     * Record a message the business sent outside this app (an agent typing in the Instagram app —
     * it reaches us as an `is_echo` webhook) so the portal inbox shows the whole conversation.
     * Messages we sent ourselves are already stored under the same mid, so those are skipped.
     */
    public function recordEcho(InstagramAccount $account, string $participantId, string $text, ?string $igMessageId, array $attachments = []): ?InstagramMessage
    {
        $convo = $this->thread($account, $participantId);
        if ($igMessageId && $convo->messages()->where('ig_message_id', $igMessageId)->exists()) {
            return null;
        }
        return $this->storeOutbound($convo, $text, 'staff_app', $igMessageId, 'sent', null, $attachments ?: null);
    }

    public function storeOutbound(
        InstagramConversation $convo,
        string $text,
        string $source,
        ?string $igMessageId,
        string $status,
        ?string $error,
        ?array $attachments = null,
    ): InstagramMessage {
        $row = [
            'instagram_conversation_id' => $convo->id,
            'ig_message_id' => $igMessageId !== null ? mb_substr($igMessageId, 0, 255) : null,
            'direction'     => 'out',
            'source'        => $source,
            'text'          => $text,
            'attachments'   => $attachments ?: null,
            'status'        => $status,
            'error'         => $error,
            'sent_at'       => $status === 'sent' ? now() : null,
        ];
        try {
            $msg = InstagramMessage::create($row);
        } catch (\Illuminate\Database\QueryException $e) {
            // The DM already went out; a bookkeeping failure (e.g. an id that doesn't fit the column) must
            // not turn into a failed run or a resend. Keep the record, drop the id.
            \Illuminate\Support\Facades\Log::warning('Instagram message copy could not be stored with its id: ' . $e->getMessage());
            $msg = InstagramMessage::create(['ig_message_id' => null] + $row);
        }

        if ($status === 'sent') {
            $convo->update([
                'last_message_preview' => mb_substr($text, 0, 140),
                'last_message_at'      => now(),
            ]);
        }
        return $msg;
    }
}
