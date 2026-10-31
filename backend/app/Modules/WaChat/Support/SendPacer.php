<?php

namespace App\Modules\WaChat\Support;

use Closure;

/**
 * Decides how long to wait after each message so a campaign looks like a person, not a bot:
 *  - every minute it sends a random 2–3 (or whatever the campaign chose) messages, spread across the minute;
 *  - no more than `cap5` messages in any 5-minute window, however high the per-minute numbers are.
 * Clock and randomness are injected so the behaviour can be tested without sleeping.
 */
final class SendPacer
{
    private float $minuteStart;
    private int $minuteQuota;
    private int $minuteSent = 0;
    /** @var float[] the last `cap5` send times — the rolling 5-minute window */
    private array $recent = [];

    /** @param Closure():float $now  @param Closure(int,int):int $rand */
    public function __construct(
        private readonly int $perMinMin,
        private readonly int $perMinMax,
        private readonly int $cap5,
        private readonly Closure $now,
        private readonly Closure $rand,
    ) {
        $t = ($this->now)();
        $this->minuteStart = $t;
        $this->minuteQuota = ($this->rand)($perMinMin, $perMinMax);
    }

    public static function make(array $pacing): self
    {
        return new self(
            (int) $pacing['per_min_min'], (int) $pacing['per_min_max'], (int) $pacing['per_5min_cap'],
            fn () => microtime(true),
            fn (int $a, int $b) => random_int($a, $b),
        );
    }

    /** Seconds to wait before the next message (call right after a message was sent). */
    public function delayAfterSend(): float
    {
        $t = ($this->now)();
        if ($t >= $this->minuteStart + 60) { $this->minuteStart += 60 * floor(($t - $this->minuteStart) / 60); $this->minuteSent = 0; $this->minuteQuota = ($this->rand)($this->perMinMin, $this->perMinMax); }

        $this->minuteSent++;
        $this->recent[] = $t;
        if (count($this->recent) > $this->cap5) array_shift($this->recent);

        $jitter = fn (float $max) => ($this->rand)(0, 1000) / 1000 * $max;

        // Rolling 5-minute cap: once `cap5` messages are within the last 5 minutes, the next one has to wait until
        // the oldest of them is 5 minutes old. (Counted over any 5 minutes, not fixed blocks.)
        $capWait    = count($this->recent) >= $this->cap5 ? max(0.0, $this->recent[0] + 300 - $t) : 0.0;
        $minuteRest = $this->minuteSent >= $this->minuteQuota ? max(0.0, $this->minuteStart + 60 - $t) : 0.0;
        // this minute's quota done and/or the 5-minute cap reached: rest for whichever is longer
        if ($capWait > 0 || $this->minuteSent >= $this->minuteQuota) {
            return max($capWait, $minuteRest) + $jitter(3);
        }
        // otherwise spread the rest of this minute's messages out, with a little randomness (±25%)
        $left = max(0.0, $this->minuteStart + 60 - $t);
        $gap  = max(0.5, ($left / ($this->minuteQuota - $this->minuteSent + 1)) * (0.75 + $jitter(0.5)));
        return $gap;
    }
}
