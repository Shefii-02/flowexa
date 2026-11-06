<?php

namespace Tests\Unit;

use App\Models\InstagramAccount;
use App\Models\InstagramFlow;
use App\Models\InstagramFlowRun;
use App\Modules\Catalog\LeadIntake;
use App\Modules\Instagram\Flow\FlowValidator;
use App\Modules\Instagram\Flow\InstagramFlowEngine;
use App\Modules\Instagram\Services\InstagramClient;
use App\Modules\Instagram\Services\InstagramConversationService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class InstagramFlowEngineTest extends TestCase
{
    private $client;
    private InstagramFlowEngine $engine;
    private InstagramConversationService $conversations;
    /** @var array<int,array> every message handed to Graph */
    private array $sent = [];
    private bool $userFollows = false;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        \DB::purge('sqlite');
        Queue::fake();
        RateLimiter::clear('ig-dm:1');

        Schema::create('instagram_accounts', function ($t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->string('ig_user_id'); $t->string('username')->nullable(); $t->string('name')->nullable();
            $t->text('access_token'); $t->boolean('is_active')->default(true); $t->boolean('ai_enabled')->default(false); $t->timestamps(); $t->softDeletes();
        });
        Schema::create('instagram_conversations', function ($t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('instagram_account_id'); $t->unsignedBigInteger('contact_id')->nullable();
            $t->unsignedBigInteger('lead_id')->nullable(); $t->string('participant_id'); $t->string('participant_username')->nullable();
            $t->string('status')->default('open'); $t->boolean('ai_enabled')->default(true); $t->unsignedInteger('unread_count')->default(0);
            $t->text('last_message_preview')->nullable(); $t->timestamp('last_message_at')->nullable(); $t->timestamp('last_inbound_at')->nullable();
            $t->json('collected')->nullable(); $t->timestamp('lead_created_at')->nullable();
            $t->json('tags')->nullable(); $t->boolean('follows_business')->nullable(); $t->timestamp('follow_checked_at')->nullable(); $t->boolean('needs_agent')->default(false);
            $t->timestamps();
        });
        Schema::create('instagram_messages', function ($t) {
            $t->id(); $t->unsignedBigInteger('instagram_conversation_id'); $t->string('ig_message_id')->nullable(); $t->string('direction'); $t->string('source');
            $t->text('text')->nullable(); $t->json('attachments')->nullable(); $t->string('status')->nullable(); $t->text('error')->nullable(); $t->timestamp('sent_at')->nullable(); $t->timestamps();
        });
        Schema::create('instagram_comment_events', function ($t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('instagram_account_id'); $t->unsignedBigInteger('instagram_automation_id')->nullable();
            $t->string('ig_comment_id')->unique(); $t->string('media_id')->nullable(); $t->string('from_id')->nullable(); $t->string('from_username')->nullable();
            $t->text('comment_text')->nullable(); $t->string('action')->default('none'); $t->text('error')->nullable(); $t->timestamps();
        });
        Schema::create('instagram_flows', function ($t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('instagram_account_id'); $t->string('name'); $t->string('trigger_type')->default('comment');
            $t->json('nodes'); $t->json('edges'); $t->boolean('is_active')->default(false); $t->unsignedSmallInteger('priority')->default(0);
            $t->unsignedInteger('triggered_count')->default(0); $t->timestamp('last_triggered_at')->nullable(); $t->timestamps(); $t->softDeletes();
            $t->json('wizard')->nullable(); $t->string('status')->default('enabled'); $t->unsignedInteger('dms_sent')->default(0); $t->unsignedInteger('leads')->default(0); $t->unsignedInteger('new_followers')->default(0);
        });
        Schema::create('instagram_flow_runs', function ($t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('instagram_account_id'); $t->unsignedBigInteger('instagram_flow_id');
            $t->unsignedBigInteger('instagram_conversation_id')->nullable(); $t->string('igsid'); $t->string('comment_id')->nullable(); $t->string('status')->default('running');
            $t->string('current_node')->nullable(); $t->string('wait_kind')->nullable(); $t->unsignedInteger('wait_token')->default(0); $t->timestamp('wait_expires_at')->nullable();
            $t->json('context')->nullable(); $t->text('error')->nullable(); $t->timestamp('finished_at')->nullable(); $t->timestamp('queued_at')->nullable(); $t->timestamps();
        });

        $this->client = Mockery::mock(InstagramClient::class)->makePartial();
        $this->client->shouldReceive('replyToComment')->andReturn([]);
        $this->client->shouldReceive('sendMessage')->andReturnUsing(function ($acc, $recipient, $message) {
            $this->sent[] = ['recipient' => $recipient, 'message' => $message];
            return ['message_id' => 'm' . count($this->sent)];
        });
        $this->client->shouldReceive('userProfile')->andReturnUsing(fn () => ['is_user_follow_business' => $this->userFollows]);

        $this->conversations = new InstagramConversationService($this->client);
        $this->engine = new InstagramFlowEngine($this->client, $this->conversations, Mockery::mock(LeadIntake::class));
    }

    private function account(): InstagramAccount
    {
        return InstagramAccount::create(['company_id' => 1, 'ig_user_id' => '1784', 'username' => 'brand', 'access_token' => 'tok', 'ai_enabled' => false]);
    }

    /** The spec's comment → private reply → follower gate flow. */
    private function priceFlow(InstagramAccount $a): InstagramFlow
    {
        return InstagramFlow::create([
            'company_id' => 1, 'instagram_account_id' => $a->id, 'name' => 'Price', 'trigger_type' => 'comment', 'is_active' => true,
            'nodes' => [
                ['id' => 'n1', 'type' => 'trigger_comment', 'data' => ['keywords' => ['price'], 'match_type' => 'any', 'reply_once_per_user' => true]],
                ['id' => 'n2', 'type' => 'public_reply', 'data' => ['texts' => ['Check your DM 📩']]],
                ['id' => 'n3', 'type' => 'private_reply', 'data' => ['text' => 'Tap below', 'buttons' => [['title' => 'Send me the price']]]],
                ['id' => 'n4', 'type' => 'condition_follow', 'data' => []],
                ['id' => 'n5', 'type' => 'send_dm', 'data' => ['text' => 'Price list: …']],
                ['id' => 'n6', 'type' => 'send_dm', 'data' => ['text' => 'Follow @brand first', 'buttons' => [['title' => 'I followed ✅']]]],
            ],
            'edges' => [
                ['source' => 'n1', 'target' => 'n2'], ['source' => 'n2', 'target' => 'n3'],
                ['source' => 'n3', 'target' => 'n4', 'sourceHandle' => 'click'],
                ['source' => 'n4', 'target' => 'n5', 'sourceHandle' => 'true'],
                ['source' => 'n4', 'target' => 'n6', 'sourceHandle' => 'false'],
                ['source' => 'n6', 'target' => 'n4', 'sourceHandle' => 'click'],
            ],
        ]);
    }

    private function payloadOfLastButton(): string
    {
        $m = end($this->sent)['message'];
        return $m['attachment']['payload']['buttons'][0]['payload'];
    }

    private function tap(InstagramAccount $a, string $payload): void
    {
        $this->conversations->recordInbound($a, 'IGSID-9', 'tap', null);   // what the postback job does first
        $this->engine->handlePostback($a, 'IGSID-9', $payload);
    }

    public function test_comment_flow_gates_the_price_on_following(): void
    {
        $a = $this->account();
        $this->priceFlow($a);

        $handled = $this->engine->handleComment($a, ['id' => 'C1', 'text' => 'PRICE please', 'from' => ['id' => 'IGSID-9', 'username' => 'john'], 'media' => ['id' => 'M1']]);

        $this->assertTrue($handled);
        $this->assertSame(['comment_id' => 'C1'], $this->sent[0]['recipient'], 'first DM is the private reply to the comment');
        $run = InstagramFlowRun::first();
        $this->assertSame(['waiting', 'click', 'n3'], [$run->status, $run->wait_kind, $run->current_node]);

        // Doesn't follow → asked to follow, parked again.
        $this->userFollows = false;
        $this->tap($a, $this->payloadOfLastButton());
        $this->assertSame('Follow @brand first', end($this->sent)['message']['attachment']['payload']['text']);
        $this->assertSame('n6', $run->fresh()->current_node);

        // Follows now → price, run done.
        $this->userFollows = true;
        $followPayload = $this->payloadOfLastButton();
        $this->tap($a, $followPayload);
        $this->assertSame('Price list: …', end($this->sent)['message']['text']);
        $this->assertSame('done', $run->fresh()->status);
        $this->assertTrue($run->conversation->fresh()->follows_business);

        // A re-delivered tap is a no-op.
        $count = count($this->sent);
        $this->tap($a, $followPayload);
        $this->assertCount($count, $this->sent);
    }

    public function test_duplicate_comment_and_second_comment_by_same_user_do_not_restart(): void
    {
        $a = $this->account();
        $this->priceFlow($a);
        $c = ['id' => 'C1', 'text' => 'price', 'from' => ['id' => 'IGSID-9', 'username' => 'john']];
        $this->engine->handleComment($a, $c);
        $this->engine->handleComment($a, $c);
        $this->engine->handleComment($a, ['id' => 'C2'] + $c);

        $this->assertSame(1, InstagramFlowRun::count());
        $this->assertCount(1, $this->sent);
    }

    public function test_non_matching_comment_is_left_to_the_legacy_bot(): void
    {
        $a = $this->account();
        $this->priceFlow($a);
        $this->assertFalse($this->engine->handleComment($a, ['id' => 'C1', 'text' => 'nice pic', 'from' => ['id' => 'IGSID-9']]));
        $this->assertSame(0, InstagramFlowRun::count());
    }

    public function test_collect_input_validates_and_saves_the_answer(): void
    {
        $a = $this->account();
        $flow = InstagramFlow::create([
            'company_id' => 1, 'instagram_account_id' => $a->id, 'name' => 'Lead', 'trigger_type' => 'dm', 'is_active' => true,
            'nodes' => [
                ['id' => 't', 'type' => 'trigger_dm', 'data' => ['keywords' => ['quote']]],
                ['id' => 'c', 'type' => 'collect_input', 'data' => ['prompt' => 'Your email?', 'field' => 'email', 'validation' => 'email', 'max_retries' => 1]],
                ['id' => 'k', 'type' => 'crm_action', 'data' => ['action' => 'add_tag', 'tag' => 'hot']],
                ['id' => 'd', 'type' => 'send_dm', 'data' => ['text' => 'Thanks {{email}}']],
            ],
            'edges' => [['source' => 't', 'target' => 'c'], ['source' => 'c', 'target' => 'k'], ['source' => 'k', 'target' => 'd']],
        ]);

        $convo = $this->conversations->recordInbound($a, 'IGSID-9', 'quote please', 'mid1', [], 'john');
        $this->assertTrue($this->engine->handleDm($a, $convo, 'quote please'));
        $this->assertSame('Your email?', $this->sent[0]['message']['text']);

        $convo = $this->conversations->recordInbound($a, 'IGSID-9', 'not an email', 'mid2');
        $this->assertTrue($this->engine->handleReply($a, $convo, 'not an email'));
        $this->assertStringContainsString("doesn't look right", $this->sent[1]['message']['text']);

        $convo = $this->conversations->recordInbound($a, 'IGSID-9', 'John@Example.com', 'mid3');
        $this->engine->handleReply($a, $convo, 'John@Example.com');

        $convo = $convo->fresh();
        $this->assertSame('john@example.com', $convo->collected['email']);
        $this->assertSame(['hot'], $convo->tags);
        $this->assertSame('Thanks john@example.com', end($this->sent)['message']['text']);
        $this->assertSame('done', InstagramFlowRun::first()->status);
        $this->assertSame($flow->id, InstagramFlowRun::first()->instagram_flow_id);
    }

    public function test_human_handover_stops_the_bot_and_flags_the_thread(): void
    {
        $a = $this->account();
        InstagramFlow::create([
            'company_id' => 1, 'instagram_account_id' => $a->id, 'name' => 'H', 'trigger_type' => 'dm', 'is_active' => true,
            'nodes' => [
                ['id' => 't', 'type' => 'trigger_dm', 'data' => ['keywords' => ['agent']]],
                ['id' => 'h', 'type' => 'human_handover', 'data' => ['message' => 'An agent will reply soon']],
            ],
            'edges' => [['source' => 't', 'target' => 'h']],
        ]);
        $convo = $this->conversations->recordInbound($a, 'IGSID-9', 'agent', 'mid1');
        $this->engine->handleDm($a, $convo, 'agent');

        $convo = $convo->fresh();
        $this->assertTrue($convo->needs_agent);
        $this->assertFalse($convo->ai_enabled);
        $this->assertSame('An agent will reply soon', $this->sent[0]['message']['text']);
    }

    public function test_validator_rejects_follower_check_before_any_interaction(): void
    {
        $v = new FlowValidator();
        $nodes = [
            ['id' => 'a', 'type' => 'trigger_comment', 'data' => []],
            ['id' => 'b', 'type' => 'private_reply', 'data' => ['text' => 'hi']],           // no button
            ['id' => 'c', 'type' => 'condition_follow', 'data' => []],
            ['id' => 'd', 'type' => 'send_dm', 'data' => ['text' => 'x']],
        ];
        $edges = [['source' => 'a', 'target' => 'b'], ['source' => 'b', 'target' => 'c'], ['source' => 'c', 'target' => 'd', 'sourceHandle' => 'true']];
        $errors = implode(' | ', $v->semantics($nodes, $edges));
        $this->assertStringContainsString('Follows us?', $errors);

        $nodes[1]['data']['buttons'] = [['title' => 'Go']];
        $edges[1]['sourceHandle'] = 'click';
        $this->assertSame([], $v->semantics($nodes, $edges));

        // A normal DM straight after a comment trigger is rejected.
        $bad = [['id' => 'a', 'type' => 'trigger_comment', 'data' => []], ['id' => 'b', 'type' => 'send_dm', 'data' => []]];
        $this->assertNotEmpty($v->semantics($bad, [['source' => 'a', 'target' => 'b']]));
    }

    public function test_hourly_dm_budget_parks_the_run_instead_of_sending(): void
    {
        $a = $this->account();
        $this->priceFlow($a);
        for ($i = 0; $i < InstagramFlowEngine::HOURLY_DM_LIMIT; $i++) RateLimiter::hit("ig-dm:{$a->id}", 3600);

        $this->engine->handleComment($a, ['id' => 'C1', 'text' => 'price', 'from' => ['id' => 'IGSID-9']]);

        $this->assertCount(0, $this->sent);
        $run = InstagramFlowRun::first();
        $this->assertSame(['waiting', 'throttle'], [$run->status, $run->wait_kind]);
        $this->assertNotNull($run->queued_at);   // queued for the drainer, not a per-run timer
    }

    public function test_queued_comments_are_sent_oldest_first_and_new_ones_cannot_jump_the_queue(): void
    {
        config(['services.instagram.dm_hourly_cap' => 2]);
        $a = $this->account();
        $this->priceFlow($a);
        $this->assertSame(1, 1);

        // 2 comments use the budget, #3-#5 are held back in arrival order.
        foreach (['C1', 'C2', 'C3', 'C4', 'C5'] as $i => $id) {
            $this->engine->handleComment($a, ['id' => $id, 'text' => 'price', 'from' => ['id' => "U$i", 'username' => "u$i"]]);
            $this->travel(1)->seconds();
        }
        $this->assertCount(2, $this->sent);
        $queued = InstagramFlowRun::where('wait_kind', 'throttle')->orderBy('queued_at')->orderBy('id')->pluck('comment_id')->all();
        $this->assertSame(['C3', 'C4', 'C5'], $queued);

        // Window frees up for 2 DMs: a brand-new comment arriving now must queue behind them.
        RateLimiter::clear("ig-dm:{$a->id}");
        $this->engine->handleComment($a, ['id' => 'C6', 'text' => 'price', 'from' => ['id' => 'U6', 'username' => 'u6']]);
        $this->assertCount(2, $this->sent, 'C6 must not overtake the queue');

        $resumed = $this->engine->drainQueue($a);
        $this->assertSame(2, $resumed);
        $this->assertCount(4, $this->sent);
        $stillQueued = InstagramFlowRun::where('wait_kind', 'throttle')->orderBy('queued_at')->orderBy('id')->pluck('comment_id')->all();
        $this->assertSame(['C5', 'C6'], $stillQueued);   // C3 and C4 went first
    }

    public function test_comment_runs_expire_in_the_queue_after_seven_days(): void
    {
        config(['services.instagram.dm_hourly_cap' => 1]);
        $a = $this->account();
        $this->priceFlow($a);
        $this->engine->handleComment($a, ['id' => 'C1', 'text' => 'price', 'from' => ['id' => 'U1']]);
        $this->engine->handleComment($a, ['id' => 'C2', 'text' => 'price', 'from' => ['id' => 'U2']]);
        InstagramFlowRun::where('comment_id', 'C2')->update(['created_at' => now()->subDays(8)]);
        RateLimiter::clear("ig-dm:{$a->id}");

        $this->engine->drainQueue($a);
        $run = InstagramFlowRun::where('comment_id', 'C2')->first();
        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('7 days', $run->error);
    }
}
