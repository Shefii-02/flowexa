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

    /** Accounts connected through "Instagram API with Instagram Login" talk to graph.instagram.com, not graph.facebook.com. */
    private function graphFor(InstagramAccount $account): MetaGraphClient
    {
        return $account->auth_type === 'instagram_login'
            ? new MetaGraphClient(config('services.instagram.ig_graph_version'), 'graph.instagram.com')
            : $this->graph;
    }

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
        return $this->graphFor($account)->get($account->ig_user_id, $account->access_token, [
            'fields' => 'id,username,name,profile_picture_url,followers_count,follows_count,media_count',
        ]);
    }

    /** Recent media (posts, reels, carousels) for the media picker in automations. */
    public function media(InstagramAccount $account, int $limit = 50): array
    {
        return $this->graphFor($account)->getAllPages("/{$account->ig_user_id}/media", $account->access_token, [
            'fields' => 'id,caption,media_type,media_product_type,media_url,thumbnail_url,permalink,timestamp,comments_count,like_count',
        ], $limit);
    }

    public function getComment(InstagramAccount $account, string $commentId): array
    {
        return $this->graphFor($account)->get($commentId, $account->access_token, [
            'fields' => 'id,text,username,timestamp,from,media{id,media_type,permalink,timestamp}',
        ]);
    }

    /** Comments on a media item (posts/reels), each with its own nested replies. */
    public function mediaComments(InstagramAccount $account, string $mediaId, int $limit = 50): array
    {
        return $this->graphFor($account)->getAllPages("/{$mediaId}/comments", $account->access_token, [
            'fields' => 'id,text,username,timestamp,like_count,hidden,replies{id,text,username,timestamp,hidden}',
        ], $limit);
    }

    /** Public reply under a comment. */
    public function replyToComment(InstagramAccount $account, string $commentId, string $message): array
    {
        return $this->graphFor($account)->post("/{$commentId}/replies", $account->access_token, ['message' => $message]);
    }

    /** Hide (or, with $hide=false, unhide) a comment from the post's public comment thread. */
    public function hideComment(InstagramAccount $account, string $commentId, bool $hide = true): array
    {
        return $this->graphFor($account)->post("/{$commentId}", $account->access_token, ['hide' => $hide ? 'true' : 'false']);
    }

    /** Permanently delete a comment (or reply — same endpoint). */
    public function deleteComment(InstagramAccount $account, string $commentId): array
    {
        return $this->graphFor($account)->delete("/{$commentId}", $account->access_token);
    }

    /** Account-level analytics — reach, impressions, profile views, follower count over `period`. */
    public function accountInsights(InstagramAccount $account, string $period = 'day', int $days = 14): array
    {
        return $this->graphFor($account)->get("/{$account->ig_user_id}/insights", $account->access_token, [
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
        return $this->graphFor($account)->get("/{$mediaId}/insights", $account->access_token, [
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

    /**
     * Send an arbitrary `message` object (text, attachment template, quick_replies…) to a recipient
     * (`['id'=>IGSID]` or `['comment_id'=>…]` for the one-per-comment private reply).
     */
    public function sendMessage(InstagramAccount $account, array $recipient, array $message, bool $humanAgent = false): array
    {
        $payload = ['recipient' => $recipient, 'message' => $message];
        // HUMAN_AGENT tag extends the window to 7 days for a real agent's reply.
        if ($humanAgent) {
            $payload['messaging_type'] = 'MESSAGE_TAG';
            $payload['tag'] = 'HUMAN_AGENT';
        }
        return $this->graphFor($account)->post("/{$account->ig_user_id}/messages", $account->access_token, $payload);
    }

    /**
     * The user's profile incl. `is_user_follow_business`. Meta only returns this once the user has
     * messaged or tapped a button, so call it after the first interaction.
     */
    public function userProfile(InstagramAccount $account, string $igsid): array
    {
        return $this->graphFor($account)->get("/{$igsid}", $account->access_token, [
            'fields' => 'name,username,profile_pic,follower_count,is_user_follow_business,is_business_follow_user',
        ]);
    }

    /** `message` body for a text + buttons template. Buttons: `[['type'=>'postback','title','payload'] | ['type'=>'web_url','title','url']]`. */
    public static function buttonTemplate(string $text, array $buttons): array
    {
        return ['attachment' => ['type' => 'template', 'payload' => [
            'template_type' => 'button',
            'text'          => mb_substr($text, 0, 640),
            'buttons'       => array_slice(array_values($buttons), 0, 3),
        ]]];
    }

    /** `message` body for text + quick replies (max 13, 20-char titles). */
    public static function quickReplies(string $text, array $replies): array
    {
        return [
            'text' => mb_substr($text, 0, 1000),
            'quick_replies' => array_map(fn ($r) => [
                'content_type' => 'text',
                'title'        => mb_substr($r['title'], 0, 20),
                'payload'      => $r['payload'],
            ], array_slice(array_values($replies), 0, 13)),
        ];
    }

    public static function imageMessage(string $url): array
    {
        return ['attachment' => ['type' => 'image', 'payload' => ['url' => $url]]];
    }

    private function send(InstagramAccount $account, array $recipient, string $message, bool $humanAgent = false): array
    {
        return $this->sendMessage($account, $recipient, ['text' => $message], $humanAgent);
    }
}
