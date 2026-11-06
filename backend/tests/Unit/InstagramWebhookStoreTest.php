<?php

namespace Tests\Unit;

use App\Models\InstagramAccount;
use App\Modules\Instagram\Jobs\ProcessInstagramEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InstagramWebhookStoreTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'services.instagram.app_secret' => 'sek']);
        DB::purge('sqlite');
        Queue::fake();
        Schema::create('instagram_accounts', function ($t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->string('ig_user_id'); $t->string('username')->nullable();
            $t->text('access_token'); $t->boolean('is_active')->default(true); $t->timestamps(); $t->softDeletes();
        });
        Schema::create('instagram_webhook_events', function ($t) {
            $t->id(); $t->unsignedBigInteger('company_id')->nullable(); $t->unsignedBigInteger('instagram_account_id')->nullable();
            $t->string('ig_user_id')->nullable(); $t->string('kind'); $t->string('field')->nullable(); $t->string('status')->default('queued');
            $t->string('note')->nullable(); $t->json('payload'); $t->timestamp('processed_at')->nullable(); $t->timestamps();
        });
        InstagramAccount::create(['company_id' => 7, 'ig_user_id' => 'IG1', 'username' => 'acme', 'access_token' => 't', 'is_active' => true]);
    }

    private function hook(array $body, ?string $sig = null)
    {
        $raw = json_encode($body);
        return $this->call('POST', '/api/v1/instagram/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => $sig ?? 'sha256=' . hash_hmac('sha256', $raw, 'sek'),
        ], $raw);
    }

    #[Test]
    public function signed_comment_is_stored_and_queued_with_its_event_id(): void
    {
        $r = $this->hook(['object' => 'instagram', 'entry' => [['id' => 'IG1', 'changes' => [['field' => 'comments',
            'value' => ['id' => 'c1', 'text' => 'link', 'from' => ['id' => 'U9', 'username' => 'bob'], 'media' => ['id' => 'm1']]]]]]]);
        $r->assertOk();
        $row = DB::table('instagram_webhook_events')->first();
        $this->assertSame('comment', $row->kind);
        $this->assertSame('queued', $row->status);
        $this->assertSame(7, (int) $row->company_id);
        Queue::assertPushed(ProcessInstagramEvent::class, fn ($j) => $j->kind === 'comment' && $j->payload['_evt'] == $row->id);
    }

    #[Test]
    public function story_reply_dm_and_postback_are_classified(): void
    {
        $this->hook(['entry' => [['id' => 'IG1', 'messaging' => [
            ['sender' => ['id' => 'U1'], 'recipient' => ['id' => 'IG1'], 'message' => ['mid' => 'm1', 'text' => 'hi', 'reply_to' => ['story' => ['id' => 's1']]]],
            ['sender' => ['id' => 'U2'], 'recipient' => ['id' => 'IG1'], 'postback' => ['mid' => 'm2', 'payload' => 'FLOW:1:2:0', 'title' => 'Send me the link']],
        ]]]])->assertOk();
        $this->assertEqualsCanonicalizing(['story_reply', 'postback'], DB::table('instagram_webhook_events')->pluck('kind')->all());
    }

    #[Test]
    public function unknown_account_and_unhandled_fields_are_stored_as_ignored(): void
    {
        $this->hook(['entry' => [['id' => 'NOPE', 'changes' => []], ['id' => 'IG1', 'changes' => [['field' => 'message_reactions', 'value' => ['x' => 1]]]]]])->assertOk();
        $this->assertSame(2, DB::table('instagram_webhook_events')->where('status', 'ignored')->count());
        Queue::assertNothingPushed();
    }

    #[Test]
    public function bad_signature_is_rejected_and_not_stored(): void
    {
        $this->hook(['entry' => [['id' => 'IG1', 'changes' => [['field' => 'comments', 'value' => ['id' => 'c']]]]]], 'sha256=deadbeef')->assertStatus(403);
        $this->assertSame(0, DB::table('instagram_webhook_events')->count());
    }

    #[Test]
    public function echo_from_the_business_is_not_treated_as_a_customer_message(): void
    {
        $this->hook(['entry' => [['id' => 'IG1', 'messaging' => [['sender' => ['id' => 'IG1'], 'recipient' => ['id' => 'U1'], 'message' => ['mid' => 'e1', 'is_echo' => true, 'text' => 'sent by us']]]]]])->assertOk();
        $this->assertSame('echo', DB::table('instagram_webhook_events')->value('kind'));
        Queue::assertPushed(ProcessInstagramEvent::class, fn ($j) => $j->kind === 'echo');
    }

    #[Test]
    public function a_delivery_signed_with_the_other_app_secret_is_accepted(): void
    {
        config(['services.instagram.app_secrets' => ['sek', 'meta-app-secret']]);
        $body = ['entry' => [['id' => 'IG1', 'changes' => [['field' => 'comments', 'value' => ['id' => 'c9', 'text' => 'x', 'from' => ['id' => 'U1']]]]]]];
        $raw = json_encode($body);
        $this->call('POST', '/api/v1/instagram/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256=' . hash_hmac('sha256', $raw, 'meta-app-secret'),
        ], $raw)->assertOk();
        $this->assertSame(1, DB::table('instagram_webhook_events')->count());
    }
}
