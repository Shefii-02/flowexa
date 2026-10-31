<?php

namespace Tests\Unit;

use App\Modules\WaChat\Support\MessageVariator;
use App\Modules\WaChat\Support\SendPacer;
use App\Modules\WaChat\Support\SenderLimits;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MessageSenderPacingTest extends TestCase
{
    /** Runs a pacer against a fake clock and returns the second each message went out at. */
    private function simulate(int $min, int $max, int $cap, int $messages, int $seed = 7): array
    {
        $clock = 1000.0;
        mt_srand($seed);
        $pacer = new SendPacer($min, $max, $cap, function () use (&$clock) { return $clock; }, fn (int $a, int $b) => mt_rand($a, $b));
        $sentAt = [];
        for ($i = 0; $i < $messages; $i++) {
            $sentAt[] = $clock;
            $clock += $pacer->delayAfterSend();
        }
        return $sentAt;
    }

    public function test_default_pace_is_two_or_three_messages_every_minute(): void
    {
        $sentAt = $this->simulate(2, 3, 15, 60);
        $perMinute = array_count_values(array_map(fn ($t) => (int) floor(($t - 1000) / 60), $sentAt));
        foreach (array_slice($perMinute, 0, -1, true) as $minute => $count) {   // last minute may be partial
            $this->assertGreaterThanOrEqual(2, $count, "minute $minute");
            $this->assertLessThanOrEqual(3, $count, "minute $minute");
        }
    }

    public function test_five_minute_cap_is_never_exceeded_even_at_high_per_minute_speed(): void
    {
        $sentAt = $this->simulate(30, 40, 50, 400);
        for ($i = 0; $i < count($sentAt); $i++) {
            $inWindow = count(array_filter($sentAt, fn ($t) => $t >= $sentAt[$i] && $t < $sentAt[$i] + 300));
            $this->assertLessThanOrEqual(50, $inWindow, 'more than 50 messages inside one 5-minute window');
        }
    }

    public function test_variator_rotates_texts_evenly_and_never_repeats_back_to_back(): void
    {
        $v = new MessageVariator(['Hi B', 'Hi C'], ['111 111 1111', '222 222 2222', '333 333 3333'], false, 42);
        $texts = $nums = [];
        for ($i = 0; $i < 30; $i++) {
            $out = $v->compose('Hi A {{share_number}}', $i);
            $texts[] = substr($out, 0, 4);
            preg_match('/\d{3} \d{3} \d{4}/', $out, $m);
            $nums[] = $m[0];
        }
        $this->assertEquals(10, array_count_values($texts)['Hi A'] ?? 0);
        $this->assertEquals(10, array_count_values($texts)['Hi B'] ?? 0);
        $this->assertEquals(10, array_count_values($nums)['222 222 2222'] ?? 0);
        for ($i = 1; $i < 30; $i++) {
            $this->assertNotSame($texts[$i], $texts[$i - 1], "text repeated at $i");
            $this->assertNotSame($nums[$i], $nums[$i - 1], "number repeated at $i");
        }
    }

    public function test_dot_lines_grow_one_two_three_then_start_again(): void
    {
        $v = new MessageVariator([], ['1234567890'], true, 1);
        $lines = [];
        for ($i = 0; $i < 7; $i++) {
            $lines[] = substr_count($v->compose('Hello', $i), MessageVariator::DOTS);
        }
        $this->assertSame([1, 2, 3, 1, 2, 3, 1], $lines);
        $this->assertStringEndsWith("\n......\n......", $v->compose('Hello', 1));
    }

    public function test_text_without_placeholder_gets_a_contact_line_before_the_dots(): void
    {
        $v = new MessageVariator([], ['9198765 43210'], true, 1);
        $this->assertSame("Hello\n\nContact: 9198765 43210\n......", $v->compose('Hello', 0));
    }

    public function test_options_default_to_two_three_per_minute_and_need_three_share_numbers(): void
    {
        $o = SenderLimits::normalize(null, SenderLimits::DEFAULTS);
        $this->assertSame(['per_min_min' => 2, 'per_min_max' => 3, 'per_5min_cap' => 15], $o['pacing']);

        $this->expectException(ValidationException::class);
        SenderLimits::normalize(['variation' => ['enabled' => true, 'share_numbers' => ['919876543210', '919876543211']]], SenderLimits::DEFAULTS);
    }

    public function test_pacing_outside_the_admin_limits_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        SenderLimits::normalize(['pacing' => ['per_min_min' => 2, 'per_min_max' => 3, 'per_5min_cap' => 501]], SenderLimits::DEFAULTS);
    }
}
