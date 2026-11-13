<?php

namespace Tests\Unit;

use App\Modules\WaChat\Models\AgentPlaybook;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AgentPlaybookScopeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('agent_playbooks', function ($t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->string('session_id')->nullable(); $t->string('template_key')->nullable(); $t->string('business_type')->nullable();
            $t->string('function_key')->default('general'); $t->json('channels')->nullable(); $t->json('abilities')->nullable(); $t->boolean('is_active')->default(true); $t->string('agent_name')->nullable(); $t->timestamps();
        });
    }

    private function agent(array $a = []): AgentPlaybook { return AgentPlaybook::create($a + ['company_id' => 1, 'agent_name' => 'A']); }

    #[Test]
    public function channel_specific_beats_all_channels_and_a_session_beats_both(): void
    {
        $general = $this->agent(['agent_name' => 'general']);
        $cloud   = $this->agent(['agent_name' => 'sales-cloud', 'function_key' => 'sales', 'channels' => ['wa_cloud']]);
        $this->assertSame($cloud->id,   AgentPlaybook::resolveFor(1, 'num1', 'wa_cloud')->id);
        $this->assertSame($general->id, AgentPlaybook::resolveFor(1, 'sess', 'wa_chat')->id, 'a cloud-only agent never answers WA Chat');

        $session = $this->agent(['session_id' => 'sess', 'agent_name' => 'sess']);
        $this->assertSame($session->id, AgentPlaybook::resolveFor(1, 'sess', 'wa_chat')->id);
    }

    #[Test]
    public function a_paused_agent_never_answers_and_hands_over_to_the_next(): void
    {
        $general = $this->agent();
        $sales = $this->agent(['function_key' => 'sales', 'channels' => ['wa_chat']]);
        $this->assertSame($sales->id, AgentPlaybook::resolveFor(1, 's', 'wa_chat')->id);
        $sales->update(['is_active' => false]);
        $this->assertSame($general->id, AgentPlaybook::resolveFor(1, 's', 'wa_chat')->id);
        $general->update(['is_active' => false]);
        $this->assertNull(AgentPlaybook::resolveFor(1, 's', 'wa_chat'));
    }

    #[Test]
    public function function_adds_a_focus_instruction(): void
    {
        $this->assertStringContainsString('SALES', $this->agent(['function_key' => 'sales'])->focusInstruction());
        $this->assertNull($this->agent()->focusInstruction());
    }

    #[Test]
    public function abilities_default_by_role_and_can_be_overridden(): void
    {
        config(['database.default' => 'sqlite']);
        Schema::create('companies', function ($t) { $t->id(); $t->unsignedBigInteger('plan_id')->nullable(); $t->timestamps(); $t->softDeletes(); });
        \Illuminate\Support\Facades\DB::table('companies')->insert(['id' => 1]);   // no plan = every module available

        $hr = $this->agent(['function_key' => 'hr']);
        $this->assertTrue($hr->can('answer_questions'));
        $this->assertFalse($hr->can('leads'), 'an HR agent never touches leads');
        $this->assertFalse($hr->can('qualify'));
        $this->assertSame([], $hr->missingRequiredSlots([]));

        $sales = $this->agent(['function_key' => 'sales']);
        $this->assertTrue($sales->can('leads') && $sales->can('followup_tasks'));
        $sales->update(['abilities' => ['answer_questions']]);
        $this->assertFalse($sales->fresh()->can('leads'));
    }
}
