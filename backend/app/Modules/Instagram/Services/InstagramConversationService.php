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

        $row = [
            'instagram_conversation_id' => $convo->id,
            'ig_message_id' => $igMessageId !== null ? mb_substr($igMessageId, 0, 255) : null,
            'direction'     => 'in',
            'source'        => 'inbound',
            'text'          => $text,
            'attachments'   => $attachments ?: null,
            'sent_at'       => now(),
        ];
        try {
            InstagramMessage::create($row);
        } catch (\Illuminate\Database\QueryException $e) {
            // A bookkeeping problem (e.g. an id that doesn't fit) must not drop the customer's message or the automation.
            \Illuminate\Support\Facades\Log::warning('Instagram inbound message stored without its id: ' . $e->getMessage());
            InstagramMessage::create(['ig_message_id' => null] + $row);
        }

        $convo->forceFill([
            'last_message_preview' => mb_substr($text ?: '[attachment]', 0, 140),
            'last_message_at'      => now(),
            'last_inbound_at'      => now(),
            'unread_count'         => $convo->unread_count + 1,
            'status'               => $convo->status === 'closed' ? 'open' : $convo->status,
        ])->save();

        return $convo;
    }

    /** Record a DM the business sent from the Instagram app itself (webhook echo), so the inbox shows it. */
    public function recordEcho(
        InstagramAccount $account,
        string $participantId,
        string $text,
        ?string $igMessageId = null,
        array $attachments = [],
    ): ?InstagramMessage {
        $convo = $this->thread($account, $participantId);

        // Our own API sends are stored with the same mid; don't duplicate them.
        if ($igMessageId && $convo->messages()->where('ig_message_id', $igMessageId)->exists()) {
            return null;
        }

        return $this->storeOutbound($convo, $text, 'app', $igMessageId, 'sent', null, $attachments ?: null);
    }

    /**
     * Send a DM in a thread and persist it. Respects the 24h messaging window unless $humanAgent.
     * Returns the created message, or null when the window is closed / the send failed.
     */
    public function send(InstagramConversation $convo, string $text, string $source = 'manual', bool $humanAgent = false, ?string $replyToMid = null): ?InstagramMessage
    {
        [$allowed, $useTag, $why] = $this->gate($convo, $humanAgent);
        if (!$allowed) {
            return $this->storeOutbound($convo, $text, $source, null, 'failed', $why, null, $replyToMid);
        }

        try {
            $message = ['text' => $text] + ($replyToMid ? ['reply_to' => ['mid' => $replyToMid]] : []);
            $res = $this->client->sendMessage($convo->account, ['id' => $convo->participant_id], $message, $useTag);
            $msg = $this->storeOutbound($convo, $text, $source, $res['message_id'] ?? null, 'sent', null, null, $replyToMid);
            $convo->update(['status' => 'open']);
            return $msg;
        } catch (\Throwable $e) {
            Log::warning('InstagramConversationService: send failed', ['conversation' => $convo->id, 'error' => $e->getMessage()]);
            return $this->storeOutbound($convo, $text, $source, null, 'failed', $e->getMessage(), null, $replyToMid);
        }
    }

    /** Send an image / video / audio (voice message) / file. `$url` must be a public https link. */
    public function sendMedia(InstagramConversation $convo, string $type, string $url, string $source = 'manual', bool $humanAgent = false, ?string $replyToMid = null): ?InstagramMessage
    {
        $att = [['type' => $type, 'url' => $url]];
        [$allowed, $useTag, $why] = $this->gate($convo, $humanAgent);
        if (!$allowed) {
            return $this->storeOutbound($convo, '', $source, null, 'failed', $why, $att, $replyToMid);
        }
        try {
            $message = InstagramClient::attachmentMessage($type, $url) + ($replyToMid ? ['reply_to' => ['mid' => $replyToMid]] : []);
            $res = $this->client->sendMessage($convo->account, ['id' => $convo->participant_id], $message, $useTag);
            $msg = $this->storeOutbound($convo, '', $source, $res['message_id'] ?? null, 'sent', null, $att, $replyToMid);
            $convo->update(['status' => 'open']);
            return $msg;
        } catch (\Throwable $e) {
            Log::warning('InstagramConversationService: media send failed', ['conversation' => $convo->id, 'error' => $e->getMessage()]);
            return $this->storeOutbound($convo, '', $source, null, 'failed', $e->getMessage(), $att, $replyToMid);
        }
    }

    /**
     * Whether a staff message may go out now: inside 24h a normal message is enough; past it, only a staff reply may use
     * the HUMAN_AGENT tag (7 days), and only once Meta has approved that feature for the app.
     * @return array{0:bool,1:bool,2:?string} allowed, use tag, reason when blocked
     */
    public function gate(InstagramConversation $convo, bool $humanAgent): array
    {
        $within = $convo->withinMessagingWindow();
        $useTag = $humanAgent && !$within && config('services.instagram.human_agent_enabled');
        if ($within || $useTag) {
            return [true, (bool) $useTag, null];
        }
        Log::info('InstagramConversationService: outside 24h window, not sending', ['conversation' => $convo->id]);
        return [false, false, $humanAgent
            ? 'Outside the 24-hour messaging window. Replies after 24 hours need Meta\'s "Human Agent" approval, which isn\'t enabled for this app yet.'
            : 'Outside the 24-hour messaging window'];
    }

    public function storeOutbound(
        InstagramConversation $convo,
        string $text,
        string $source,
        ?string $igMessageId,
        string $status,
        ?string $error,
        ?array $attachments = null,
        ?string $replyToMid = null,
    ): InstagramMessage {
        $row = [
            'reply_to_mid'  => $replyToMid !== null ? mb_substr($replyToMid, 0, 255) : null,
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
                'last_message_preview' => mb_substr($text !== '' ? $text : self::mediaLabel($attachments), 0, 140),
                'last_message_at'      => now(),
            ]);
        }
        return $msg;
    }

    /** Reactions each channel accepts. */
    public static function reactionsFor(InstagramConversation $convo): array
    {
        return $convo->account?->channel === 'facebook' ? ['love', 'like', 'smile', 'wow', 'sad', 'angry', 'dislike'] : ['love'];
    }

    /** React to a message (or remove the reaction with null). Needs the 24h window like any other message. */
    public function react(InstagramConversation $convo, InstagramMessage $msg, ?string $reaction): InstagramMessage
    {
        if ($reaction !== null && !in_array($reaction, self::reactionsFor($convo), true)) {
            throw new \InvalidArgumentException('That reaction is not supported on this channel.');
        }
        [$allowed, , $why] = $this->gate($convo, false);
        if (!$allowed) {
            throw new \RuntimeException($why);
        }
        if (!$msg->ig_message_id) {
            throw new \RuntimeException('This message cannot be reacted to (it has no Instagram message id).');
        }
        $this->client->react($convo->account, $convo->participant_id, $msg->ig_message_id, $reaction);
        $msg->update(['my_reaction' => $reaction]);
        return $msg;
    }

    private static function mediaLabel(?array $attachments): string
    {
        $type = $attachments[0]['type'] ?? null;
        return match ($type) { 'image' => '📷 Photo', 'video' => '🎬 Video', 'audio' => '🎤 Voice message', 'file' => '📎 File', default => '' };
    }
}
