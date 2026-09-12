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
    /**
     * Every Graph permission this integration needs. Request all of these on the Meta app —
     * they're also listed in the in-app Setup Guide so a company owner knows what to grant when
     * generating their Page access token.
     *
     *  - instagram_basic            → read the IG account's own profile + media
     *  - instagram_manage_comments  → read, reply to, delete and hide comments
     *  - instagram_manage_insights  → account/media analytics (reach, impressions, engagement)
     *  - instagram_manage_messages  → send/receive DMs
     *  - pages_show_list            → list the Facebook Pages the token can manage (account picker)
     *  - pages_read_engagement      → read Page engagement stats shown next to each page
     *  - pages_manage_metadata      → subscribe the Page to webhooks (comments/messages events)
     *  - pages_messaging            → send messages as the Page (private replies, DM fallback)
     */
    public const REQUIRED_PERMISSIONS = [
        'instagram_basic',
        'instagram_manage_comments',
        'instagram_manage_insights',
        'instagram_manage_messages',
        'pages_show_list',
        'pages_read_engagement',
        'pages_manage_metadata',
        'pages_messaging',
    ];

    public function __construct(private readonly MetaGraphClient $graph) {}

    /**
     * List the Facebook Pages a User access token can manage, each with its own Page access
     * token and — when linked — the Instagram Business Account behind it. This is the "Connect
     * via Facebook" account picker: paste a User token once, then pick a page instead of typing
     * IDs by hand. Requires pages_show_list (+ pages_read_engagement for the extra fields).
     */
    public function listPages(string $userAccessToken): array
    {
        $pages = $this->graph->getAllPages('/me/accounts', $userAccessToken, [
            'fields' => 'id,name,access_token,fan_count,link,instagram_business_account{id,username,name,profile_picture_url,followers_count}',
        ], 100);

        return collect($pages)->map(fn ($p) => [
            'page_id'      => $p['id'],
            'page_name'    => $p['name'] ?? null,
            'page_token'   => $p['access_token'] ?? null,
            'fan_count'    => $p['fan_count'] ?? null,
            'page_link'    => $p['link'] ?? null,
            'ig_linked'    => isset($p['instagram_business_account']),
            'ig_user_id'   => $p['instagram_business_account']['id'] ?? null,
            'ig_username'  => $p['instagram_business_account']['username'] ?? null,
            'ig_name'      => $p['instagram_business_account']['name'] ?? null,
            'ig_avatar'    => $p['instagram_business_account']['profile_picture_url'] ?? null,
            'ig_followers' => $p['instagram_business_account']['followers_count'] ?? null,
        ])->all();
    }

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

    /** Comments on a media item (posts/reels), each with its own nested replies. */
    public function mediaComments(InstagramAccount $account, string $mediaId, int $limit = 50): array
    {
        return $this->graph->getAllPages("/{$mediaId}/comments", $account->access_token, [
            'fields' => 'id,text,username,timestamp,like_count,hidden,replies{id,text,username,timestamp,hidden}',
        ], $limit);
    }

    /** Public reply under a comment. */
    public function replyToComment(InstagramAccount $account, string $commentId, string $message): array
    {
        return $this->graph->post("/{$commentId}/replies", $account->access_token, ['message' => $message]);
    }

    /** Hide (or, with $hide=false, unhide) a comment from the post's public comment thread. */
    public function hideComment(InstagramAccount $account, string $commentId, bool $hide = true): array
    {
        return $this->graph->post("/{$commentId}", $account->access_token, ['hide' => $hide ? 'true' : 'false']);
    }

    /** Permanently delete a comment (or reply — same endpoint). */
    public function deleteComment(InstagramAccount $account, string $commentId): array
    {
        return $this->graph->delete("/{$commentId}", $account->access_token);
    }

    /** Account-level analytics — reach, impressions, profile views, follower count over `period`. */
    public function accountInsights(InstagramAccount $account, string $period = 'day', int $days = 14): array
    {
        return $this->graph->get("/{$account->ig_user_id}/insights", $account->access_token, [
            'metric'     => 'reach,accounts_engaged,profile_views,follower_count',
            'period'     => $period,
            'metric_type' => 'time_series',
            'since'      => now()->subDays($days)->timestamp,
            'until'      => now()->timestamp,
        ]);
    }

    /** Per-post analytics for the media picker / post detail (likes, comments, reach, saves). */
    public function mediaInsights(InstagramAccount $account, string $mediaId): array
    {
        return $this->graph->get("/{$mediaId}/insights", $account->access_token, [
            'metric' => 'reach,likes,comments,saved,shares,total_interactions',
        ]);
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
