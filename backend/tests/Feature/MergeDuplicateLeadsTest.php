<?php

namespace Tests\Feature;

use App\Models\Lead;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MergeDuplicateLeadsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('leads', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('contact_id')->nullable(); $t->string('category')->nullable();
            $t->string('stage')->default('new'); $t->string('priority')->default('medium'); $t->string('source')->nullable(); $t->text('notes')->nullable();
            $t->unsignedBigInteger('assigned_to')->nullable(); $t->timestamp('assigned_at')->nullable(); $t->timestamps(); $t->softDeletes();
        });
        Schema::create('lead_events', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('lead_id'); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('user_id')->nullable();
            $t->string('event'); $t->json('payload')->nullable(); $t->timestamps();
        });
        Lead::unguard();
    }

    protected function tearDown(): void { Lead::reguard(); parent::tearDown(); }

    private function lead(array $x): Lead { return Lead::create($x + ['company_id' => 1, 'contact_id' => 5, 'category' => 'Admission', 'stage' => 'new']); }

    public function test_repeat_open_leads_of_one_contact_and_category_are_joined_into_the_oldest(): void
    {
        $a = $this->lead(['notes' => 'first']);
        $b = $this->lead(['notes' => 'second', 'assigned_to' => 9]);
        DB::table('lead_events')->insert(['lead_id' => $b->id, 'company_id' => 1, 'event' => 'note_added', 'created_at' => now(), 'updated_at' => now()]);

        $this->artisan('leads:merge-duplicates')->assertSuccessful();

        $this->assertNull(Lead::find($b->id));                         // soft-deleted
        $keep = Lead::find($a->id);
        $this->assertSame(9, (int) $keep->assigned_to);                // inherited the owner it lacked
        $this->assertStringContainsString("[merged from lead #{$b->id}] second", $keep->notes);
        $this->assertSame(1, DB::table('lead_events')->where('lead_id', $a->id)->where('event', 'note_added')->count());  // history moved
        $this->assertSame(1, DB::table('lead_events')->where('lead_id', $a->id)->where('event', 'lead_merged')->count());
    }

    public function test_different_category_other_contact_and_closed_leads_are_left_alone(): void
    {
        $this->lead([]);
        $this->lead(['category' => 'Fees']);                 // other category
        $this->lead(['contact_id' => 6]);                    // other person
        $this->lead(['stage' => 'enrolled']);                // finished
        $this->lead(['stage' => 'lost']);
        $this->artisan('leads:merge-duplicates')->assertSuccessful();
        $this->assertSame(5, Lead::count());
    }

    public function test_dry_run_changes_nothing(): void
    {
        $this->lead([]); $this->lead([]);
        $this->artisan('leads:merge-duplicates --dry-run')->assertSuccessful();
        $this->assertSame(2, Lead::count());
    }
}
