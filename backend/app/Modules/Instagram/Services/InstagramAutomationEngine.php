<?php

namespace App\Modules\Instagram\Services;

use App\Models\InstagramAccount;
use App\Models\InstagramAutomation;
use App\Models\InstagramCommentEvent;
use Illuminate\Support\Facades\Log;

/**
 * The comment-keyword bot. When someone comments a trigger keyword on the connected account's post
 * or reel, the matching automation sends that person a DM (private reply to the comment) and,
 * optionally, a public comment reply — then optionally hands the thread to the AI agent.
 */
class InstagramAutomationEngine
{
    public function __construct(
        private readonly InstagramClient $client,
        private readonly InstagramConversationService $conversations,
    ) {}

    /**
     * Handle one `comments` webhook change. `$value` is Meta's payload:
     * `{ id, text, from:{id,username}, media:{id,media_product_type} }`.
     */
    public function handleComment(InstagramAccount $account, array $value): void
    {
        $commentId = $value['id'] ?? null;
        if (!$commentId) {
            return;
        }

        // Dedup — Meta re-delivers. The unique index on ig_comment_id is the real guard; this row
        // also becomes the audit trail for what we did.
        $event = InstagramCommentEvent::firstOrNew(['ig_comment_id' => $commentId]);
        if ($event->exists) {
            return;
        }

        $fromId   = $value['from']['id'] ?? null;
        $username = $value['from']['username'] ?? null;
        $text     = (string) ($value['text'] ?? '');
        $mediaId  = $value['media']['id'] ?? ($value['media_id'] ?? null);

        $event->fill([
            'company_id'           => $account->company_id,
            'instagram_account_id' => $account->id,
            'media_id'             => $mediaId,
            'from_id'              => $fromId,
            'from_username'        => $username,
            'comment_text'         => mb_substr($text, 0, 1000),
            'action'               => 'none',
        ]);

        // Ignore the account's own comments/replies.
        if ($fromId && $fromId === $account->ig_user_id) {
            $event->save();
            return;
        }

        // If media id wasn't in the payload, fetch it (needed for "selected media" scoping).
        if (!$mediaId) {
            try {
                $mediaId = $this->client->getComment($account, $commentId)['media']['id'] ?? null;
                $event->media_id = $mediaId;
            } catch (\Throwable $e) {
                Log::debug('InstagramAutomationEngine: could not fetch comment media', ['error' => $e->getMessage()]);
            }
        }

        $automation = $this->pickAutomation($account, $text, $mediaId);
        if (!$automation) {
            $event->save();
            return;
        }

        // "Reply once per user" — don't DM the same person twice for the same rule.
        if ($automation->reply_once_per_user && $fromId
            && InstagramCommentEvent::where('instagram_automation_id', $automation->id)
                ->where('from_id', $fromId)
                ->whereIn('action', ['dm_sent', 'both', 'public_replied'])
                ->exists()) {
            $event->fill(['instagram_automation_id' => $automation->id, 'action' => 'skipped_duplicate'])->save();
            return;
        }

        $event->instagram_automation_id = $automation->id;
        $actions = [];

        // 1. Private reply → the customer's DM.
        try {
            $dm = $this->personalize($automation->dm_message, $username);
            $this->client->privateReply($account, $commentId, $dm);
            $actions[] = 'dm_sent';

            // Surface the thread in the inbox so a human (or the AI) can follow up.
            if ($username || $fromId) {
                $convo = $this->conversations->thread($account, (string) $fromId, $username);
                $this->conversations->storeOutbound($convo, $dm, 'automation', null, 'sent', null);
                if ($automation->handoff_to_ai) {
                    $convo->update(['ai_enabled' => true]);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('InstagramAutomationEngine: private reply failed', ['comment' => $commentId, 'error' => $e->getMessage()]);
            $event->error = $e->getMessage();
        }

        // 2. Optional public comment reply.
        if ($automation->public_reply) {
            try {
                $this->client->replyToComment($account, $commentId, $this->personalize($automation->public_reply, $username));
                $actions[] = 'public_replied';
            } catch (\Throwable $e) {
                Log::warning('InstagramAutomationEngine: public reply failed', ['comment' => $commentId, 'error' => $e->getMessage()]);
            }
        }

        $event->action = match (true) {
            in_array('dm_sent', $actions, true) && in_array('public_replied', $actions, true) => 'both',
            in_array('dm_sent', $actions, true)        => 'dm_sent',
            in_array('public_replied', $actions, true) => 'public_replied',
            default => 'failed',
        };
        $event->save();

        if ($actions) {
            $automation->forceFill([
                'triggered_count'   => $automation->triggered_count + 1,
                'last_triggered_at' => now(),
            ])->save();
        }
    }

    /** Also lets a DM keyword fire an automation (trigger = 'dm'). Returns the automation, if any. */
    public function pickAutomation(InstagramAccount $account, string $text, ?string $mediaId, string $trigger = 'comment'): ?InstagramAutomation
    {
        return $account->automations()
            ->where('is_active', true)
            ->where('trigger', $trigger)
            ->orderByDesc('priority')
            ->get()
            ->first(fn (InstagramAutomation $a) => $a->coversMedia($mediaId) && $a->matches($text));
    }

    private function personalize(string $template, ?string $username): string
    {
        return str_replace(
            ['{{username}}', '{{name}}'],
            [$username ? '@' . ltrim($username, '@') : 'there', $username ?: 'there'],
            $template
        );
    }
}
