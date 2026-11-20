<?php

namespace Tests\Feature;

use App\Jobs\ClassifyConversationTopic;
use App\Models\Company;
use App\Models\ConversationTopic;
use App\Services\Topics\ConversationTopicService;
use App\Services\Topics\TopicAi;
use App\Services\Topics\TopicRules;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ConversationTopicTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('companies', function (Blueprint $t) { $t->id(); $t->text('settings')->nullable(); $t->timestamps(); $t->softDeletes(); });
        Schema::create('conversation_topics', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('contact_id')->nullable(); $t->unsignedBigInteger('session_id')->nullable();
            $t->string('chat_id'); $t->string('phone')->nullable(); $t->string('topic'); $t->decimal('confidence', 3, 2)->default(0); $t->string('reason')->nullable();
            $t->string('language')->nullable(); $t->string('source')->default('rule'); $t->string('msgs_hash', 40)->nullable(); $t->unsignedSmallInteger('message_count')->default(0);
            $t->timestamp('classified_at')->nullable(); $t->timestamps();
        });
        Cache::flush();
    }

    private function company(array $topic = [], int $id = 1): Company
    {
        DB::table('companies')->updateOrInsert(['id' => $id], ['settings' => json_encode($topic ? ['topic_classifier' => $topic] : []), 'created_at' => now(), 'updated_at' => now()]);
        return Company::find($id);
    }

    private function customer(string ...$t): array { return array_map(fn ($x) => ['from' => 'customer', 'text' => $x], $t); }

    // ── the keyword rules ────────────────────────────────────────────────────────────────────────────────────────────────────────

    #[\PHPUnit\Framework\Attributes\DataProvider('clearMessages')]
    public function test_clear_messages_are_labelled_confidently(array $texts, string $topic): void
    {
        $r = TopicRules::classify($texts);
        $this->assertSame($topic, $r['topic'], json_encode($texts, JSON_UNESCAPED_UNICODE) . ' → ' . json_encode($r['scores']));
        $this->assertTrue($r['confident']);
    }

    public static function clearMessages(): array
    {
        return [
            'english price'       => [['Hello, what is the price of the 2BHK?'], 'lead_enquiry'],
            'manglish demo'       => [['demo venam, price enthaa'], 'lead_enquiry'],
            'hinglish'            => [['kitna price hai? demo chahiye'], 'lead_enquiry'],
            'malayalam script'    => [['വില എത്ര'], 'lead_enquiry'],
            'hindi script'        => [['कीमत कितना है'], 'lead_enquiry'],
            'greeting then ask'   => [['Hi', 'Need details of the course'], 'lead_enquiry'],
            'not working'         => [['app work cheyyunnilla, login problem'], 'technical'],
            'otp'                 => [["I'm not receiving otp, cant login"], 'technical'],
            'hinglish tech'       => [['kaam nahi kar raha, error aa raha'], 'technical'],
            'invoice'             => [['My invoice is wrong, refund venam'], 'billing'],
            'gst'                 => [['Please send the GST invoice for my payment'], 'billing'],
            'complaint'           => [['This is the worst service, I was cheated'], 'complaint'],
            'hinglish complaint'  => [['bahut bakwas service, shikayat hai'], 'complaint'],
            'order status'        => [['Where is my order status?'], 'existing_customer'],
            'thanks'              => [['thanks'], 'other'],
            'hi only'             => [['Hi'], 'other'],
        ];
    }

    public function test_mixed_signals_are_not_confident(): void
    {
        $r = TopicRules::classify(['I want to buy but my payment failed']);
        $this->assertFalse($r['confident']);
        $this->assertSame('billing' === $r['topic'] || 'lead_enquiry' === $r['topic'], true);
        $this->assertFalse(TopicRules::classify(['the weather is nice today and the sky is blue'])['confident']);
    }

    public function test_weak_words_alone_never_decide_a_label(): void
    {
        foreach (['sorry for late reply', 'send me info', 'need help with app', 'it is late, sorry'] as $t) {
            $r = TopicRules::classify([$t]);
            $this->assertFalse($r['confident'], $t);
            $this->assertSame('other', $r['topic'], $t . ' must not be stored as ' . $r['topic']);
        }
    }

    public function test_a_message_with_two_different_topics_is_left_to_the_ai(): void
    {
        foreach (['what is the price? also app is crashing', 'I already purchased last week, how to install?', 'I want to buy but my payment failed'] as $t) {
            $this->assertFalse(TopicRules::classify([$t])['confident'], $t);
        }
    }

    public function test_words_match_whole_words_only(): void
    {
        $this->assertSame('other', TopicRules::classify(['billion dollars company'])['topic']);
        $this->assertSame(0, array_sum(TopicRules::score('ratesheet pricey')));        // "rate" inside a longer word does not count
    }

    public function test_repeating_a_word_does_not_run_up_the_score_and_the_newest_message_counts_double(): void
    {
        $this->assertSame(TopicRules::score('price price price price')['lead_enquiry'], TopicRules::score('price price')['lead_enquiry']);
        // earlier: price enquiry; now: the app is broken → the latest message decides
        $this->assertSame('technical', TopicRules::classify(['price?', 'app not working error'])['topic']);
    }

    public function test_trivial_messages_never_need_the_ai(): void
    {
        foreach (['hi', 'Hello!!', 'ok', 'thanks', '👍', 'ശരി', 'good morning'] as $t) { $this->assertTrue(TopicRules::isTrivial($t), $t); }
        $this->assertFalse(TopicRules::isTrivial('hi what is the price'));
    }

    // ── the AI's answer is parsed strictly ────────────────────────────────────────────────────────────────────────────────────

    public function test_ai_answers_are_validated(): void
    {
        $ok = TopicAi::parse("```json\n{\"topic\":\"technical\",\"confidence\":0.82,\"reason\":\"login broken\",\"language\":\"mixed\"}\n```");
        $this->assertSame(['topic' => 'technical', 'confidence' => 0.82, 'reason' => 'login broken', 'language' => 'mixed'], $ok);
        $this->assertSame('billing', TopicAi::parse('Sure! {"topic":"billing","confidence":7}')['topic']);
        $this->assertSame(1.0, TopicAi::parse('{"topic":"billing","confidence":7}')['confidence'], 'confidence is clamped');
        $this->assertNull(TopicAi::parse('{"topic":"sales_pitch","confidence":0.9}'), 'a topic outside the list is rejected, not guessed');
        $this->assertNull(TopicAi::parse('not json at all'));
        $this->assertNull(TopicAi::parse('{"confidence":0.9}'));
    }

    // ── the service: decision order ────────────────────────────────────────────────────────────────────────────────────────────

    private function service(?TopicAi $ai = null): ConversationTopicService
    {
        return new ConversationTopicService($ai ?? \Mockery::mock(TopicAi::class));
    }

    public function test_a_confident_rule_result_is_stored_without_calling_the_ai(): void
    {
        $ai = \Mockery::mock(TopicAi::class); $ai->shouldNotReceive('available'); $ai->shouldNotReceive('classify');
        $row = $this->service($ai)->classify($this->company(['use_ai' => true]), '9198@c.us', '9198', 5, 11, $this->customer('price of the villa?'));
        $this->assertSame('lead_enquiry', $row->topic);
        $this->assertSame('rule', $row->source);
        $this->assertSame([5, 11], [$row->session_id, $row->contact_id]);
    }

    public function test_when_the_rules_are_unsure_the_ai_decides_if_allowed(): void
    {
        $ai = \Mockery::mock(TopicAi::class);
        $ai->shouldReceive('available')->once()->andReturn(true);
        $ai->shouldReceive('classify')->once()->andReturn(['topic' => 'billing', 'confidence' => 0.88, 'reason' => 'payment failed while buying', 'language' => 'en']);
        $row = $this->service($ai)->classify($this->company(['use_ai' => true]), 'c1', null, null, null, $this->customer('I want to buy but my payment failed'));
        $this->assertSame(['billing', 'ai', 0.88], [$row->topic, $row->source, $row->confidence]);
    }

    public function test_without_ai_the_rules_best_guess_is_kept_but_flagged_low_confidence(): void
    {
        $ai = \Mockery::mock(TopicAi::class); $ai->shouldReceive('available')->andReturn(false); $ai->shouldNotReceive('classify');
        $row = $this->service($ai)->classify($this->company(), 'c1', null, null, null, $this->customer('I want to buy but my payment failed'));
        $this->assertSame('rule', $row->source);
        $this->assertLessThan(0.6, $row->confidence);
    }

    public function test_an_ai_failure_falls_back_to_the_rules_guess(): void
    {
        $ai = \Mockery::mock(TopicAi::class); $ai->shouldReceive('available')->andReturn(true); $ai->shouldReceive('classify')->andReturn(null);
        $row = $this->service($ai)->classify($this->company(['use_ai' => true]), 'c1', null, null, null, $this->customer('I want to buy but my payment failed'));
        $this->assertSame('rule', $row->source);
    }

    public function test_unchanged_text_is_not_classified_again_and_changed_text_updates_the_same_row(): void
    {
        $ai = \Mockery::mock(TopicAi::class); $ai->shouldNotReceive('available');
        $svc = $this->service($ai); $co = $this->company();
        $a = $svc->classify($co, 'c1', null, null, null, $this->customer('price?'));
        $stamp = DB::table('conversation_topics')->value('classified_at');
        $b = $svc->classify($co, 'c1', null, null, null, $this->customer('price?'));
        $this->assertSame($a->id, $b->id);
        $this->assertSame($stamp, DB::table('conversation_topics')->value('classified_at'));

        $c = $svc->classify($co, 'c1', null, null, null, $this->customer('price?', 'app not working error'));
        $this->assertSame($a->id, $c->id);
        $this->assertSame('technical', $c->topic);
        $this->assertSame(1, ConversationTopic::count());
    }

    public function test_a_label_chosen_by_staff_is_never_overwritten(): void
    {
        $svc = $this->service(); $co = $this->company();
        $row = $svc->classify($co, 'c1', null, null, null, $this->customer('price?'));
        $row->update(['topic' => 'complaint', 'source' => 'staff', 'confidence' => 1]);
        $again = $svc->classify($co, 'c1', null, null, null, $this->customer('price?', 'app not working error crash'));
        $this->assertSame(['complaint', 'staff'], [$again->topic, $again->source]);
    }

    public function test_it_does_nothing_when_switched_off_or_when_the_customer_said_nothing(): void
    {
        $this->assertNull($this->service()->classify($this->company(['enabled' => false]), 'c1', null, null, null, $this->customer('price?')));
        $this->assertNull($this->service()->classify($this->company(), 'c2', null, null, null, [['from' => 'us', 'text' => 'Hello, how can I help?']]));
        $this->assertSame(0, ConversationTopic::count());
    }

    public function test_conversations_of_different_companies_are_separate(): void
    {
        $svc = $this->service();
        $svc->classify($this->company([], 1), 'same-chat', null, null, null, $this->customer('price?'));
        $svc->classify($this->company([], 2), 'same-chat', null, null, null, $this->customer('app not working error'));
        $this->assertSame(['lead_enquiry'], ConversationTopic::where('company_id', 1)->pluck('topic')->all());
        $this->assertSame(['technical'], ConversationTopic::where('company_id', 2)->pluck('topic')->all());
    }

    // ── scheduling (debounce) ─────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_a_burst_of_messages_schedules_one_delayed_job(): void
    {
        Queue::fake(); $this->company();
        ClassifyConversationTopic::schedule(1, 5, '9198@c.us', '9198', 'price?');
        ClassifyConversationTopic::schedule(1, 5, '9198@c.us', '9198', 'and the demo?');
        ClassifyConversationTopic::schedule(1, 5, '9199@c.us', '9199', 'another customer');
        Queue::assertPushed(ClassifyConversationTopic::class, 2);
        Queue::assertPushed(ClassifyConversationTopic::class, fn ($j) => $j->chatId === '9198@c.us' && $j->delay !== null);
    }

    public function test_media_only_group_and_switched_off_never_schedule(): void
    {
        Queue::fake(); $this->company(); $this->company(['enabled' => false], 2);
        ClassifyConversationTopic::schedule(1, 5, 'c', 'p', '   ');
        ClassifyConversationTopic::schedule(1, 5, '120363@g.us', 'p', 'price?');
        ClassifyConversationTopic::schedule(2, 5, 'c', 'p', 'price?');
        ClassifyConversationTopic::schedule(99, 5, 'c', 'p', 'price?');   // unknown company: no error
        Queue::assertNothingPushed();
    }
}
