<?php

namespace Tests\Feature;

use App\Models\LeadAssignmentRule;
use App\Services\LeadAssignment\ScheduledAutoAssigner;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** When is a company's auto-assign cron run due? (interval, run window, instant safety net) */
class ScheduledAutoAssignerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'UTC']);
        date_default_timezone_set('UTC');   // the model re-parses date attributes in the app timezone
    }

    private function rule(array $x = []): LeadAssignmentRule
    {
        return (new LeadAssignmentRule())->forceFill($x + ['auto_assign_enabled' => true, 'auto_assign_run_mode' => 'scheduled',
            'auto_assign_interval_minutes' => 15, 'timezone' => 'UTC', 'auto_assign_last_run_at' => null]);
    }

    private function due(LeadAssignmentRule $r, string $now): bool
    {
        return app(ScheduledAutoAssigner::class)->isDue($r, Carbon::parse($now, 'UTC'));
    }

    public function test_off_is_never_due_and_first_run_is_due(): void
    {
        $this->assertFalse($this->due($this->rule(['auto_assign_enabled' => false]), '2026-11-19 10:00'));
        $this->assertTrue($this->due($this->rule(), '2026-11-19 10:00'));
    }

    public function test_scheduled_waits_for_its_interval(): void
    {
        $r = $this->rule(['auto_assign_last_run_at' => Carbon::parse('2026-11-19 10:00', 'UTC')]);
        $this->assertFalse($this->due($r, '2026-11-19 10:14'));
        $this->assertTrue($this->due($r, '2026-11-19 10:15'));
    }

    public function test_instant_mode_only_gets_the_five_minute_safety_net(): void
    {
        $r = $this->rule(['auto_assign_run_mode' => 'instant', 'auto_assign_interval_minutes' => 60, 'auto_assign_last_run_at' => Carbon::parse('2026-11-19 10:00', 'UTC')]);
        $this->assertFalse($this->due($r, '2026-11-19 10:04'));
        $this->assertTrue($this->due($r, '2026-11-19 10:05'));
    }

    public function test_run_window_daytime_and_overnight(): void
    {
        $day = $this->rule(['auto_assign_run_start' => '09:00:00', 'auto_assign_run_end' => '18:00:00']);
        $this->assertFalse($this->due($day, '2026-11-19 08:59'));
        $this->assertTrue($this->due($day, '2026-11-19 12:00'));
        $this->assertFalse($this->due($day, '2026-11-19 18:01'));

        $night = $this->rule(['auto_assign_run_start' => '22:00:00', 'auto_assign_run_end' => '06:00:00']);
        $this->assertTrue($this->due($night, '2026-11-19 23:30'));
        $this->assertTrue($this->due($night, '2026-11-20 05:00'));
        $this->assertFalse($this->due($night, '2026-11-19 12:00'));
    }

    public function test_window_uses_the_rule_timezone(): void
    {
        $r = $this->rule(['timezone' => 'Asia/Kolkata', 'auto_assign_run_start' => '09:00:00', 'auto_assign_run_end' => '18:00:00']);
        $this->assertTrue($this->due($r, '2026-11-19 04:00'));    // 09:30 IST
        $this->assertFalse($this->due($r, '2026-11-19 13:00'));   // 18:30 IST
    }
}
