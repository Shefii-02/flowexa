<?php

namespace Tests\Feature;

use App\Models\FlowBuilder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** trigger_keywords must always be a list — the importer used to double-encode it, which crashed the flow-builders page. */
class FlowBuilderTriggerKeywordsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('flow_builders', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('created_by')->nullable(); $t->string('name');
            $t->text('description')->nullable(); $t->boolean('is_active')->default(false); $t->string('trigger_type')->default('default');
            $t->json('trigger_keywords')->nullable(); $t->dateTime('active_from')->nullable(); $t->dateTime('active_until')->nullable();
            $t->unsignedInteger('total_sessions')->default(0); $t->unsignedInteger('total_leads')->default(0); $t->json('settings')->nullable(); $t->timestamps();
        });
    }

    private function make(mixed $kw): FlowBuilder
    {
        return FlowBuilder::create(['company_id' => 1, 'name' => 'f', 'trigger_type' => 'keyword', 'trigger_keywords' => $kw]);
    }

    public function test_an_array_is_stored_as_a_plain_json_list(): void
    {
        $f = $this->make(['hi', 'menu']);
        $this->assertSame('["hi","menu"]', DB::table('flow_builders')->where('id', $f->id)->value('trigger_keywords'));
        $this->assertSame(['hi', 'menu'], $f->fresh()->trigger_keywords);
    }

    public function test_the_importers_old_pre_encoded_string_is_no_longer_double_encoded(): void
    {
        $f = $this->make(json_encode(['hi', 'menu']));
        $this->assertSame('["hi","menu"]', DB::table('flow_builders')->where('id', $f->id)->value('trigger_keywords'));
    }

    public function test_an_already_double_encoded_row_reads_back_as_a_list(): void
    {
        $id = DB::table('flow_builders')->insertGetId(['company_id' => 1, 'name' => 'old', 'trigger_keywords' => json_encode(json_encode(['hi', 'menu'])), 'created_at' => now(), 'updated_at' => now()]);
        $kw = FlowBuilder::find($id)->trigger_keywords;
        $this->assertIsArray($kw);
        $this->assertSame(['hi', 'menu'], $kw);
    }

    public function test_null_empty_and_junk_become_an_empty_list(): void
    {
        $this->assertSame([], $this->make(null)->fresh()->trigger_keywords);
        $this->assertSame([], $this->make([])->fresh()->trigger_keywords);
        $id = DB::table('flow_builders')->insertGetId(['company_id' => 1, 'name' => 'x', 'trigger_keywords' => '"not json list"', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame([], FlowBuilder::find($id)->trigger_keywords);
    }

    public function test_repair_migration_fixes_bad_rows_and_leaves_good_ones(): void
    {
        $bad = DB::table('flow_builders')->insertGetId(['company_id' => 1, 'name' => 'bad', 'trigger_keywords' => json_encode(json_encode(['a', 'b'])), 'created_at' => now(), 'updated_at' => now()]);
        $good = DB::table('flow_builders')->insertGetId(['company_id' => 1, 'name' => 'good', 'trigger_keywords' => '["x"]', 'created_at' => now(), 'updated_at' => now()]);
        (require database_path('migrations/2026_11_19_000010_repair_double_encoded_flow_trigger_keywords.php'))->up();
        $this->assertSame('["a","b"]', DB::table('flow_builders')->where('id', $bad)->value('trigger_keywords'));
        $this->assertSame('["x"]', DB::table('flow_builders')->where('id', $good)->value('trigger_keywords'));
    }
}
