<?php

namespace App\Modules\Instagram\Services;

use App\Models\InstagramAccount;
use App\Modules\MetaAds\Services\MetaGraphClient;

/**
 * Instagram Messaging + Graph calls, on top of the shared {@see MetaGraphClient} (retries, typed
 * errors). Every call uses the account's page access token.
 *
 * NOTE: endpoint shapes follow the Instagram-native messaging API (`/{ig-user-id}/messages`). If a
 * given app is still on the Messenger-Platform routing (`/me/messages` with the page token), only
 * the path in `send()` needs to change.
 */
class InstagramClient
{
    public function __construct(private readonly MetaGraphClient $graph) {}

    public function accountInfo(InstagramAccount $account): array
    {
        return $this->graph->get($account->ig_user_id, $account->access_token, [
            'fields' => 'id,username,name,profile_picture_url,followers_count,follows_count,media_count',
        ]);
    }

    /** Recent media (posts, reels, carousels) for the media picker in automations. */
    public function media(InstagramAccount $account, int $limit = 50): array
    {
        return $this->graph->getAllPages("/{$account->ig_user_id}/media", $account->access_token, [
            'fields' => 'id,caption,media_type,media_product_type,media_url,thumbnail_url,permalink,timestamp,comments_count,like_count',
        ], $limit);
    }

    public function getComment(InstagramAccount $account, string $commentId): array
    {
        return $this->graph->get($commentId, $account->access_token, [
            'fields' => 'id,text,username,timestamp,from,media{id,media_type,permalink}',
        ]);
    }

    /** Public reply under a comment. */
    public function replyToComment(InstagramAccount $account, string $commentId, string $message): array
    {
        return $this->graph->post("/{$commentId}/replies", $account->access_token, ['message' => $message]);
    }

    /** Private (DM) reply to a comment — the "comment keyword → DM" flow. One per comment, 7-day window. */
    public function privateReply(InstagramAccount $account, string $commentId, string $message): array
    {
        return $this->send($account, ['comment_id' => $commentId], $message);
    }

    /** Send a DM to a user by their IGSID (scoped, per-conversation id from the messaging webhook). */
    public function sendDm(InstagramAccount $account, string $igsid, string $message, bool $humanAgent = false): array
    {
        $recipient = ['id' => $igsid];
        return $this->send($account, $recipient, $message, $humanAgent);
    }

    private function send(InstagramAccount $account, array $recipient, string $message, bool $humanAgent = false): array
    {
        $payload = [
            'recipient' => $recipient,
            'message'   => ['text' => $message],
        ];
        // HUMAN_AGENT tag extends the window to 7 days for a real agent's reply.
        if ($humanAgent) {
            $payload['messaging_type'] = 'MESSAGE_TAG';
            $payload['tag'] = 'HUMAN_AGENT';
        }
        return $this->graph->post("/{$account->ig_user_id}/messages", $account->access_token, $payload);
    }
}
