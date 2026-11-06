<?php

namespace Tests\Unit;

use App\Models\InstagramAccount;
use App\Modules\Instagram\Services\InstagramClient;
use App\Modules\MetaAds\Services\MetaGraphClient;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MessengerClientTest extends TestCase
{
    private function page(): InstagramAccount
    {
        return new InstagramAccount(['channel' => 'facebook', 'ig_user_id' => 'PAGE1', 'access_token' => 'PT', 'auth_type' => 'page']);
    }

    #[Test]
    public function public_reply_on_a_page_comment_uses_the_comments_edge(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['id' => 'new'])]);
        (new InstagramClient(new MetaGraphClient('v21.0')))->replyToComment($this->page(), 'p_c1', 'Check your inbox');
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/p_c1/comments') && $r['message'] === 'Check your inbox');
    }

    #[Test]
    public function dm_and_private_reply_use_the_page_messages_edge_with_the_page_token(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['message_id' => 'm1'])]);
        $c = new InstagramClient(new MetaGraphClient('v21.0'));
        $c->sendMessage($this->page(), ['comment_id' => 'p_c1'], ['text' => 'hi']);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/PAGE1/messages') && $r['recipient'] === ['comment_id' => 'p_c1']
            && $r->hasHeader('Authorization', 'Bearer PT'));
    }

    #[Test]
    public function page_posts_are_shaped_like_media_and_comments_are_normalised(): void
    {
        Http::fake([
            'graph.facebook.com/*/PAGE1/posts*' => Http::response(['data' => [['id' => 'p_1', 'message' => 'Hello', 'full_picture' => 'https://x/i.jpg', 'permalink_url' => 'https://fb/p', 'created_time' => '2026-11-01T00:00:00+0000']]]),
            'graph.facebook.com/*/p_c1*' => Http::response(['id' => 'p_c1', 'message' => 'price', 'from' => ['id' => 'U1', 'name' => 'Asha'], 'post' => ['id' => 'p_1', 'created_time' => '2026-11-01T00:00:00+0000']]),
        ]);
        $c = new InstagramClient(new MetaGraphClient('v21.0'));
        $media = $c->media($this->page());
        $this->assertSame(['p_1', 'Hello', 'https://x/i.jpg'], [$media[0]['id'], $media[0]['caption'], $media[0]['thumbnail_url']]);
        $cm = $c->getComment($this->page(), 'p_c1');
        $this->assertSame('p_1', $cm['media']['id']);
        $this->assertSame('price', $cm['text']);
    }

    #[Test]
    public function messenger_profile_has_no_follow_flag(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['name' => 'Asha'])]);
        $c = new InstagramClient(new MetaGraphClient('v21.0'));
        $c->userProfile($this->page(), 'PSID1');
        Http::assertSent(fn ($r) => !str_contains($r->url(), 'is_user_follow_business'));
    }
}
