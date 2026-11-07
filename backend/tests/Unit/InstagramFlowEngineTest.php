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
    /** @var string[] comment ids Instagram reports as deleted */
    private array $deletedComments = [];
    /** @var array<int,array> reactions sent to Graph */
    private array $reactions = [];

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
            $t->text('text')->nullable(); $t->json('attachments')->nullable(); $t->string('status')->nullable(); $t->text('error')->nullable(); $t->timestamp('sent_at')->nullable(); $t->string('reply_to_mid')->nullable(); $t->string('my_reaction')->nullable(); $t->timestamps();
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
        $this->client->shouldReceive('sendMessage')->andReturnUsing(function ($acc, $recipient, $message, $humanAgent = false) {
            $this->sent[] = ['recipient' => $recipient, 'message' => $message, 'human_agent' => $humanAgent];
            return ['message_id' => 'm' . count($this->sent)];
        });
        $this->client->shouldReceive('getComment')->andReturnUsing(function ($acc, $id) {
            if (in_array($id, $this->deletedComments, true)) {
                throw new \App\Modules\MetaAds\Services\MetaApiException('Object with ID does not exist', 100, 33, null, 400);
            }
            return ['id' => $id, 'media' => ['id' => 'M1', 'timestamp' => now()->subDay()->toIso8601String()]];
        });
        $this->client->shouldReceive('react')->andReturnUsing(function ($acc, $igsid, $mid, $reaction) { $this->reactions[] = [$igsid, $mid, $reaction]; return []; });
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

    private function commentFlow(InstagramAccount $a, string $dedupe): void
    {
        InstagramFlow::create([
            'company_id' => 1, 'instagram_account_id' => $a->id, 'name' => 'Dedupe', 'trigger_type' => 'comment', 'is_active' => true,
            'nodes' => [
                ['id' => 'n1', 'type' => 'trigger_comment', 'data' => ['keywords' => ['link'], 'dedupe' => $dedupe]],
                ['id' => 'n2', 'type' => 'public_reply', 'data' => ['texts' => ['Sent!']]],
                ['id' => 'n3', 'type' => 'private_reply', 'data' => ['text' => 'Here you go']],
            ],
            'edges' => [['source' => 'n1', 'target' => 'n2'], ['source' => 'n2', 'target' => 'n3']],
        ]);
    }

    private function comment(InstagramAccount $a, string $id, string $media = 'M1', string $user = 'U1'): void
    {
        $this->engine->handleComment($a, ['id' => $id, 'text' => 'link', 'from' => ['id' => $user, 'username' => 'bob'], 'media' => ['id' => $media]]);
    }

    public function test_same_person_commenting_again_on_the_same_post_is_answered_only_once(): void
    {
        $a = $this->account();
        $this->commentFlow($a, 'once_per_post');
        $this->comment($a, 'C1'); $this->comment($a, 'C2'); $this->comment($a, 'C3');
        $this->assertSame(1, InstagramFlowRun::count());
        $this->assertCount(1, $this->sent);   // one DM, not three
        $this->assertSame(2, \App\Models\InstagramCommentEvent::where('action', 'skipped_duplicate')->count());
        $this->assertStringContainsString('already answered', (string) $this->engine->lastNote);
    }

    public function test_once_per_post_still_answers_the_same_person_on_a_different_post(): void
    {
        $a = $this->account();
        $this->commentFlow($a, 'once_per_post');
        $this->comment($a, 'C1', 'M1'); $this->comment($a, 'C2', 'M2');
        $this->assertSame(2, InstagramFlowRun::count());
    }

    public function test_once_per_24h_allows_a_repeat_the_next_day_and_always_allows_every_comment(): void
    {
        $a = $this->account();
        $this->commentFlow($a, 'once_per_24h');
        $this->comment($a, 'C1');
        InstagramFlowRun::query()->update(['created_at' => now()->subDays(2)]);
        $this->comment($a, 'C2');
        $this->assertSame(2, InstagramFlowRun::count());
        $this->comment($a, 'C3');   // within 24h of C2 → skipped
        $this->assertSame(2, InstagramFlowRun::count());

        InstagramFlow::query()->delete();
        $this->commentFlow($a, 'always');
        $this->comment($a, 'C4'); $this->comment($a, 'C5');
        $this->assertSame(4, InstagramFlowRun::count());
    }

    public function test_a_claim_in_progress_blocks_a_concurrent_duplicate(): void
    {
        $a = $this->account();
        $this->commentFlow($a, 'once_per_post');
        $flowId = InstagramFlow::value('id');
        $lock = \Illuminate\Support\Facades\Cache::lock("ig-claim:{$flowId}:U1:M1", 30);
        $this->assertTrue($lock->get());                 // worker A is mid-way
        $this->comment($a, 'C1');                        // worker B must not start a second run
        $this->assertSame(0, InstagramFlowRun::count());
        $lock->release();
    }

    public function test_deleting_and_reposting_the_comment_sends_the_dm_again(): void
    {
        $a = $this->account();
        $this->commentFlow($a, 'once_per_post');
        $this->comment($a, 'C1');
        $this->assertCount(1, $this->sent);

        $this->comment($a, 'C2');                      // still there → just a repeat
        $this->assertCount(1, $this->sent);

        $this->deletedComments = ['C1'];               // they deleted C1 (DM never arrived) and posted again
        $this->comment($a, 'C3');
        $this->assertCount(2, $this->sent);
        $this->assertSame(2, InstagramFlowRun::count());
        $this->assertStringContainsString('deleted and re-posted', (string) $this->engine->lastNote);
    }

    public function test_a_run_that_never_delivered_does_not_block_a_retry(): void
    {
        $a = $this->account();
        $this->commentFlow($a, 'once_per_post');
        $this->comment($a, 'C1');
        InstagramFlowRun::query()->update(['status' => 'failed', 'context' => json_encode(['media_id' => 'M1'])]);   // DM never went out
        $this->comment($a, 'C2');
        $this->assertSame(2, InstagramFlowRun::count());
    }

    private function staffReply(bool $inWindow, bool $approved): array
    {
        config(['services.instagram.human_agent_enabled' => $approved]);
        $a = $this->account();
        $convo = $this->conversations->thread($a, 'U1', 'bob');
        $convo->update(['last_inbound_at' => $inWindow ? now()->subHour() : now()->subDays(3)]);
        $msg = $this->conversations->send($convo->fresh(), 'Hello from staff', 'manual', humanAgent: true);
        return [$msg, end($this->sent) ?: null];
    }

    public function test_staff_reply_inside_24h_is_a_plain_message_without_the_human_agent_tag(): void
    {
        [$msg, $sent] = $this->staffReply(inWindow: true, approved: false);
        $this->assertSame('sent', $msg->status);
        $this->assertFalse($sent['human_agent']);
    }

    public function test_staff_reply_after_24h_is_blocked_with_a_clear_message_until_meta_approves_human_agent(): void
    {
        [$msg, $sent] = $this->staffReply(inWindow: false, approved: false);
        $this->assertSame('failed', $msg->status);
        $this->assertStringContainsString('Human Agent', $msg->error);
        $this->assertNull($sent);   // nothing was sent to Meta
    }

    public function test_staff_reply_after_24h_uses_the_tag_once_the_feature_is_approved(): void
    {
        [$msg, $sent] = $this->staffReply(inWindow: false, approved: true);
        $this->assertSame('sent', $msg->status);
        $this->assertTrue($sent['human_agent']);
    }

    public function test_visit_profile_button_opens_the_business_profile_not_the_commenters(): void
    {
        $a = $this->account();   // @brand
        InstagramFlow::create([
            'company_id' => 1, 'instagram_account_id' => $a->id, 'name' => 'Follow', 'trigger_type' => 'dm', 'is_active' => true,
            'nodes' => [
                ['id' => 'n1', 'type' => 'trigger_dm', 'data' => ['keywords' => ['hi']]],
                ['id' => 'n2', 'type' => 'send_dm', 'data' => ['text' => 'Follow us', 'buttons' => [
                    ['type' => 'url', 'title' => 'Visit Profile', 'url' => '{{profile_url}}', 'track' => false],
                    ['type' => 'postback', 'title' => 'I am following'],
                ]]],
            ],
            'edges' => [['source' => 'n1', 'target' => 'n2']],
        ]);
        $convo = $this->conversations->recordInbound($a, 'U1', 'hi', null, [], 'customer_name');
        $this->engine->handleDm($a, $convo, 'hi');

        $buttons = $this->sent[0]['message']['attachment']['payload']['buttons'];
        $this->assertSame('https://www.instagram.com/brand/', $buttons[0]['url']);
        $this->assertStringNotContainsString('customer_name', $buttons[0]['url']);
    }

    private function openThread(bool $inWindow = true): \App\Models\InstagramConversation
    {
        $a = $this->account();
        $convo = $this->conversations->thread($a, 'U1', 'bob');
        $convo->update(['last_inbound_at' => $inWindow ? now()->subHour() : now()->subDays(3)]);
        return $convo->fresh();
    }

    public function test_staff_can_send_an_image_voice_message_or_file_with_a_quoted_reply(): void
    {
        $convo = $this->openThread();
        $msg = $this->conversations->sendMedia($convo, 'audio', 'https://cdn.test/voice.mp3', 'manual', true, 'MID-ORIGINAL');
        $this->assertSame('sent', $msg->status);
        $sent = end($this->sent)['message'];
        $this->assertSame('audio', $sent['attachment']['type']);
        $this->assertSame('https://cdn.test/voice.mp3', $sent['attachment']['payload']['url']);
        $this->assertSame(['mid' => 'MID-ORIGINAL'], $sent['reply_to']);
        $this->assertSame('MID-ORIGINAL', $msg->reply_to_mid);
        $this->assertSame('🎤 Voice message', $convo->fresh()->last_message_preview);
    }

    public function test_text_reply_can_quote_a_message(): void
    {
        $convo = $this->openThread();
        $this->conversations->send($convo, 'Sure!', 'manual', true, 'MID-Q');
        $this->assertSame(['mid' => 'MID-Q'], end($this->sent)['message']['reply_to']);
    }

    public function test_media_is_blocked_outside_the_24h_window_until_human_agent_is_approved(): void
    {
        config(['services.instagram.human_agent_enabled' => false]);
        $convo = $this->openThread(inWindow: false);
        $msg = $this->conversations->sendMedia($convo, 'image', 'https://cdn.test/a.jpg', 'manual', true);
        $this->assertSame('failed', $msg->status);
        $this->assertCount(0, $this->sent);
    }

    public function test_reaction_is_sent_stored_and_can_be_removed(): void
    {
        $convo = $this->openThread();
        $in = $this->conversations->recordInbound($convo->account, 'U1', 'hi', 'MID-IN');
        $m = $convo->messages()->where('ig_message_id', 'MID-IN')->first();

        $this->conversations->react($convo, $m, 'love');
        $this->assertSame(['U1', 'MID-IN', 'love'], $this->reactions[0]);
        $this->assertSame('love', $m->fresh()->my_reaction);

        $this->conversations->react($convo, $m->fresh(), null);
        $this->assertNull($m->fresh()->my_reaction);
        $this->assertNull($this->reactions[1][2]);
    }

    public function test_instagram_only_accepts_the_love_reaction(): void
    {
        $convo = $this->openThread();
        $this->conversations->recordInbound($convo->account, 'U1', 'hi', 'MID-IN');
        $m = $convo->messages()->where('ig_message_id', 'MID-IN')->first();
        $this->expectException(\InvalidArgumentException::class);
        $this->conversations->react($convo, $m, 'angry');
    }
}
