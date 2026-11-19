<?php

namespace Tests\Feature;

use App\Models\LeadRoutingRule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Category / campaign routing rules: which one matches a lead, and when. */
class LeadRoutingRuleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('lead_routing_rules', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->string('name'); $t->string('match_type'); $t->string('category')->nullable();
            $t->unsignedBigInteger('campaign_id')->nullable(); $t->dateTime('starts_at')->nullable(); $t->dateTime('ends_at')->nullable();
            $t->json('staff_ids'); $t->string('strategy')->nullable(); $t->boolean('is_active')->default(true); $t->unsignedSmallInteger('priority')->default(100); $t->timestamps();
        });
    }

    private function rule(array $x): LeadRoutingRule
    {
        return LeadRoutingRule::create($x + ['company_id' => 1, 'name' => 'r', 'match_type' => 'category', 'staff_ids' => [1, 2]]);
    }

    public function test_category_matches_case_insensitively_and_only_for_the_company(): void
    {
        $this->rule(['category' => 'New Course']);
        $this->assertNotNull(LeadRoutingRule::matchFor(1, 'new course', null));
        $this->assertNull(LeadRoutingRule::matchFor(1, 'Other', null));
        $this->assertNull(LeadRoutingRule::matchFor(2, 'New Course', null));
        $this->assertNull(LeadRoutingRule::matchFor(1, null, null));
    }

    public function test_campaign_rule_matches_one_campaign_or_any_inside_the_period(): void
    {
        $this->rule(['match_type' => 'campaign', 'campaign_id' => 7]);
        $this->assertNotNull(LeadRoutingRule::matchFor(1, null, 7));
        $this->assertNull(LeadRoutingRule::matchFor(1, null, 8));
        $this->assertNull(LeadRoutingRule::matchFor(1, null, null));

        $this->rule(['match_type' => 'campaign', 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay(), 'staff_ids' => [3]]);
        $this->assertSame([1, 2], LeadRoutingRule::matchFor(1, null, 7)->staff_ids);   // specific one wins by id order
        $this->assertSame([3], LeadRoutingRule::matchFor(1, null, 8)->staff_ids);      // any campaign in the period
    }

    public function test_period_pause_and_priority(): void
    {
        $this->rule(['category' => 'A', 'starts_at' => now()->addDay()]);
        $this->assertNull(LeadRoutingRule::matchFor(1, 'A', null), 'not started yet');
        LeadRoutingRule::query()->delete();

        $this->rule(['category' => 'A', 'ends_at' => now()->subHour()]);
        $this->assertNull(LeadRoutingRule::matchFor(1, 'A', null), 'already ended');
        LeadRoutingRule::query()->delete();

        $this->rule(['category' => 'A', 'is_active' => false]);
        $this->assertNull(LeadRoutingRule::matchFor(1, 'A', null), 'paused');

        $this->rule(['category' => 'A', 'priority' => 50, 'staff_ids' => [9]]);
        $this->rule(['category' => 'A', 'priority' => 10, 'staff_ids' => [5]]);
        $this->assertSame([5], LeadRoutingRule::matchFor(1, 'A', null)->staff_ids);
    }
}
