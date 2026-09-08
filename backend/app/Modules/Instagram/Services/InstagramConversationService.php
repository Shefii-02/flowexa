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

        if (!$humanAgent && !$convo->withinMessagingWindow()) {
            Log::info('InstagramConversationService: outside 24h window, not sending', ['conversation' => $convo->id]);
            return $this->storeOutbound($convo, $text, $source, null, 'failed', 'Outside the 24-hour messaging window');
        }

        try {
            $res = $this->client->sendDm($account, $convo->participant_id, $text, $humanAgent);
            $msg = $this->storeOutbound($convo, $text, $source, $res['message_id'] ?? null, 'sent', null);
            $convo->update(['status' => 'open']);
            return $msg;
        } catch (\Throwable $e) {
            Log::warning('InstagramConversationService: send failed', ['conversation' => $convo->id, 'error' => $e->getMessage()]);
            return $this->storeOutbound($convo, $text, $source, null, 'failed', $e->getMessage());
        }
    }

    public function storeOutbound(
        InstagramConversation $convo,
        string $text,
        string $source,
        ?string $igMessageId,
        string $status,
        ?string $error,
    ): InstagramMessage {
        $msg = InstagramMessage::create([
            'instagram_conversation_id' => $convo->id,
            'ig_message_id' => $igMessageId,
            'direction'     => 'out',
            'source'        => $source,
            'text'          => $text,
            'status'        => $status,
            'error'         => $error,
            'sent_at'       => $status === 'sent' ? now() : null,
        ]);

        if ($status === 'sent') {
            $convo->update([
                'last_message_preview' => mb_substr($text, 0, 140),
                'last_message_at'      => now(),
            ]);
        }
        return $msg;
    }
}
