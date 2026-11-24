<?php

namespace Tests\Feature;

use App\Jobs\NotifyLeadEvent;
use App\Models\Lead;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Who is told when a lead is assigned, transferred or taken back. */
class LeadObserverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('leads', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('contact_id')->nullable(); $t->unsignedBigInteger('assigned_to')->nullable();
            $t->unsignedBigInteger('assigned_by')->nullable(); $t->timestamp('assigned_at')->nullable(); $t->string('stage')->default('new'); $t->string('source')->nullable();
            $t->timestamps(); $t->softDeletes();
        });
        Schema::create('users', function (Blueprint $t) { $t->id(); $t->string('name')->nullable(); $t->timestamps(); $t->softDeletes(); });
        Queue::fake();
    }

    private function lead(array $x = []): Lead
    {
        $l = new Lead(); $l->forceFill($x + ['company_id' => 1, 'stage' => 'new'])->save();
        return $l;
    }

    private function kinds(): array
    {
        return Queue::pushed(NotifyLeadEvent::class)->map(fn ($j) => (new \ReflectionProperty($j, 'kind'))->getValue($j))->values()->all();
    }

    public function test_a_lead_created_with_an_owner_tells_that_person(): void
    {
        $this->lead(['assigned_to' => 5]);
        $this->assertSame(['assigned'], $this->kinds());
    }

    public function test_an_unassigned_new_lead_queues_the_waiting_notice_for_the_heads(): void
    {
        $this->lead();
        $this->assertSame(['new_waiting'], $this->kinds());
    }

    public function test_assigning_transferring_and_recalling(): void
    {
        $l = $this->lead(['assigned_to' => 5]); Queue::fake();
        $l->update(['assigned_to' => 6]);
        $this->assertSame(['transferred_in', 'transferred_out'], $this->kinds());

        Queue::fake(); $l->update(['assigned_to' => null]);
        $this->assertSame(['recalled'], $this->kinds());

        Queue::fake(); $l->update(['assigned_to' => 7]);
        $this->assertSame(['assigned'], $this->kinds());
    }

    public function test_other_edits_tell_nobody(): void
    {
        $l = $this->lead(['assigned_to' => 5]); Queue::fake();
        $l->update(['stage' => 'contacted']);
        $this->assertSame([], $this->kinds());
    }
}
