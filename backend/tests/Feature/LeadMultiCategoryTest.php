<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LeadCategoryLink;
use App\Models\LeadRoutingRule;
use App\Services\LeadCategorizer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** A lead can hold several categories; leads.category stays the primary one and every older single-category writer keeps working. */
class LeadMultiCategoryTest extends TestCase
{
    private LeadCategorizer $cat;

    protected function setUp(): void
    {
        parent::setUp();
        Lead::forgetLinksMemo();
        Schema::create('leads', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('contact_id')->nullable(); $t->string('category')->nullable();
            $t->string('stage')->default('new'); $t->string('priority')->default('medium'); $t->timestamps(); $t->softDeletes();
        });
        Schema::create('lead_category_links', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('lead_id'); $t->string('name', 120); $t->timestamps(); $t->unique(['lead_id', 'name']);
        });
        Lead::unguard();
        $this->cat = new LeadCategorizer();
    }

    protected function tearDown(): void { Lead::reguard(); Lead::forgetLinksMemo(); parent::tearDown(); }

    private function lead(?string $category = null): Lead
    {
        return Lead::create(['company_id' => 1, 'contact_id' => 1, 'category' => $category]);
    }

    private function names(Lead $l): array { return Lead::find($l->id)->categoryNames(); }

    public function test_a_single_category_set_the_old_way_becomes_the_first_link(): void
    {
        $l = $this->lead('Sales');
        $this->assertSame(['Sales'], $this->names($l));
        $this->assertSame(1, LeadCategoryLink::where('lead_id', $l->id)->count());
    }

    public function test_add_keeps_the_primary_and_replace_remove_and_clear_work(): void
    {
        $l = $this->lead('Sales');
        $this->cat->add($l, ['Support', 'VIP']);
        $this->assertSame(['Sales', 'Support', 'VIP'], $this->names($l));
        $this->assertSame('Sales', Lead::find($l->id)->category);

        $this->cat->remove(Lead::find($l->id), ['Sales']);
        $this->assertSame(['Support', 'VIP'], $this->names($l), 'removing the primary promotes the next one');
        $this->assertSame('Support', Lead::find($l->id)->category);

        $this->cat->replace(Lead::find($l->id), ['Other']);
        $this->assertSame(['Other'], $this->names($l));

        $this->cat->replace(Lead::find($l->id), []);
        $this->assertSame([], $this->names($l));
        $this->assertNull(Lead::find($l->id)->category);
        $this->assertSame(0, LeadCategoryLink::where('lead_id', $l->id)->count());
    }

    public function test_duplicates_collapse_regardless_of_case_and_spaces(): void
    {
        $l = $this->lead();
        $this->cat->replace($l, ['VIP', 'vip', ' VIP ', '', 'Sales']);
        $this->assertSame(['VIP', 'Sales'], $this->names($l));
    }

    public function test_plans_are_pure_and_do_not_write(): void
    {
        $l = $this->lead('Sales');
        $this->assertSame(['Sales', 'VIP'], $this->cat->planAdd($l, ['VIP']));
        $this->assertSame([], $this->cat->planRemove($l, ['sales']));
        $this->assertSame(['X'], $this->cat->planReplace($l, ['X']));
        $this->assertSame(['Sales'], $this->names($l), 'nothing was written');
    }

    public function test_changing_the_primary_the_old_way_keeps_the_other_categories(): void
    {
        $l = $this->lead('Sales');
        $this->cat->add($l, ['Support']);
        Lead::find($l->id)->update(['category' => 'Admissions']);
        $this->assertSame(['Admissions', 'Support'], $this->names($l));

        Lead::find($l->id)->update(['category' => null]);
        $this->assertSame(['Support'], $this->names($l), 'clearing the primary only drops that one');
    }

    public function test_in_category_scope_finds_second_categories(): void
    {
        $a = $this->lead('Sales'); $this->cat->add($a, ['VIP']);
        $b = $this->lead('Sales'); $c = $this->lead('VIP');
        $this->assertEqualsCanonicalizing([$a->id, $c->id], Lead::inCategory('VIP')->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$a->id, $b->id], Lead::inCategory('Sales')->pluck('id')->all());
    }

    public function test_an_unsaved_lead_reports_its_single_category_without_touching_the_database(): void
    {
        $this->assertSame(['Sales'], (new Lead())->forceFill(['category' => 'Sales'])->categoryNames());
        $this->assertSame([], (new Lead())->categoryNames());
    }

    public function test_routing_rules_match_any_of_a_leads_categories(): void
    {
        Schema::create('lead_routing_rules', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->string('name'); $t->string('match_type'); $t->string('category')->nullable();
            $t->unsignedBigInteger('campaign_id')->nullable(); $t->dateTime('starts_at')->nullable(); $t->dateTime('ends_at')->nullable();
            $t->string('staff_scope')->default('selected'); $t->json('staff_ids')->nullable(); $t->string('strategy')->nullable();
            $t->boolean('is_active')->default(true); $t->unsignedSmallInteger('priority')->default(100); $t->timestamps();
        });
        LeadRoutingRule::create(['company_id' => 1, 'name' => 'vip', 'match_type' => 'category', 'category' => 'VIP', 'staff_ids' => [9]]);
        $this->assertNotNull(LeadRoutingRule::matchFor(1, ['Sales', 'vip'], null), 'second category matches, any case');
        $this->assertNotNull(LeadRoutingRule::matchFor(1, 'VIP', null), 'a plain string still works');
        $this->assertNull(LeadRoutingRule::matchFor(1, ['Sales', 'Support'], null));
        $this->assertNull(LeadRoutingRule::matchFor(1, [], null));
        $this->assertNull(LeadRoutingRule::matchFor(1, null, null));
    }

    public function test_nothing_breaks_before_the_migration_has_run(): void
    {
        Schema::drop('lead_category_links');
        Lead::forgetLinksMemo();
        $l = $this->lead('Sales');                       // the model hook must not blow up creating a lead
        $l->update(['category' => 'Support']);
        $this->assertSame(['Support'], Lead::find($l->id)->categoryNames());
        $this->assertSame([$l->id], Lead::inCategory('Support')->pluck('id')->all(), 'the scope falls back to the single column');
    }
}
