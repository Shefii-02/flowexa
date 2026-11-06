<?php

namespace Tests\Unit;

use App\Models\InstagramFlow;
use App\Modules\Instagram\Flow\{FlowValidator, WizardCompiler};
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InstagramWizardCompilerTest extends TestCase
{
    private function full(): array
    {
        return [
            'trigger_type' => 'post_comment', 'media_scope' => 'specific', 'media_ids' => ['m1'],
            'keyword_mode' => 'specific', 'include_keywords' => ['link'], 'exclude_keywords' => ['spam'],
            'comment_reply_enabled' => true, 'comment_replies' => ['a', 'b', 'c'],
            'follow_gate' => ['enabled' => true, 'fail_action' => 'send'],
            'email_gate' => ['enabled' => true, 'fail_action' => 'stop'],
            'primary_message' => ['text' => 'Hi {{name}}', 'buttons' => [['title' => 'Shop', 'url' => 'https://x.test']]],
            'followups' => [
                ['condition' => 'link_not_clicked', 'delay_amount' => 1, 'delay_unit' => 'hours', 'text' => 'Still keen?'],
                ['condition' => 'no_response', 'delay_amount' => 2, 'delay_unit' => 'hours', 'text' => 'Hello?'],
            ],
        ];
    }

    #[Test]
    public function full_wizard_compiles_to_a_valid_flow(): void
    {
        $c = (new WizardCompiler)->compile($this->full());
        $v = new FlowValidator;
        $this->assertSame([], $v->structure($c['nodes'], $c['edges']));
        $this->assertSame([], $v->semantics($c['nodes'], $c['edges']));
        $this->assertSame('comment', $c['trigger_type']);
        $types = array_column($c['nodes'], 'type');
        foreach (['public_reply', 'private_reply', 'condition_follow', 'collect_input', 'send_dm', 'delay', 'condition_check'] as $t) {
            $this->assertContains($t, $types);
        }
    }

    #[Test]
    public function simple_comment_flow_skips_the_starter(): void
    {
        $c = (new WizardCompiler)->compile([
            'trigger_type' => 'post_comment', 'include_keywords' => ['hi'],
            'primary_message' => ['text' => 'Here you go', 'buttons' => [['title' => 'Open', 'url' => 'https://x.test']]],
        ]);
        $this->assertSame(['trigger_comment', 'private_reply'], array_column($c['nodes'], 'type'));
        $this->assertSame([], (new FlowValidator)->semantics($c['nodes'], $c['edges']));
    }

    #[Test]
    public function dm_trigger_with_gates_validates(): void
    {
        $w = $this->full(); $w['trigger_type'] = 'story_reply'; $w['comment_reply_enabled'] = false;
        $c = (new WizardCompiler)->compile($w);
        $this->assertSame('story_reply', $c['trigger_type']);
        $this->assertSame([], (new FlowValidator)->semantics($c['nodes'], $c['edges']));
    }

    #[Test]
    public function validation_catches_wizard_mistakes(): void
    {
        $w = $this->full();
        $w['comment_replies'] = ['a', 'b'];
        $w['primary_message']['buttons'][0]['title'] = str_repeat('x', 21);
        $w['followups'][0]['delay_amount'] = 30;
        $e = implode(' ', (new WizardCompiler)->validate($w));
        $this->assertStringContainsString('3 public comment replies', $e);
        $this->assertStringContainsString('20 characters', $e);
        $this->assertStringContainsString('23 hours', $e);
    }

    #[Test]
    public function trigger_matching_handles_exclusions_and_scopes(): void
    {
        $mk = fn (array $d, string $type = 'comment') => new InstagramFlow(['trigger_type' => $type, 'nodes' => [['id' => 'trigger', 'type' => 'trigger_comment', 'data' => $d]]]);
        $this->assertTrue($mk(['keywords' => ['link']])->matchesTrigger('Send LINK pls', null));
        $this->assertFalse($mk(['keywords' => ['link'], 'exclude_keywords' => ['spam']])->matchesTrigger('link spam', null));
        $this->assertTrue($mk(['keywords' => []])->matchesTrigger('anything', null));           // any comment
        $this->assertFalse($mk(['keywords' => ['x'], 'media_scope' => 'specific', 'media_ids' => ['m1']])->matchesTrigger('x', 'm2'));
        $this->assertTrue($mk(['keywords' => ['x'], 'media_scope' => 'next', 'locked_media_id' => 'm9'])->matchesTrigger('x', 'm9'));
        $this->assertFalse($mk(['keywords' => ['x'], 'media_scope' => 'next', 'locked_media_id' => 'm9'])->matchesTrigger('x', 'm1'));
    }

    #[Test]
    public function automated_email_step_follows_the_email_gate(): void
    {
        $w = $this->full(); $w['send_email'] = ['enabled' => true, 'subject' => 'Thanks', 'body' => 'Hi {{name}}'];
        $c = (new WizardCompiler)->compile($w);
        $types = array_column($c['nodes'], 'type');
        $this->assertContains('send_email', $types);
        $v = new FlowValidator;
        $this->assertSame([], $v->structure($c['nodes'], $c['edges']));
        $this->assertSame([], $v->semantics($c['nodes'], $c['edges']));
    }
}
