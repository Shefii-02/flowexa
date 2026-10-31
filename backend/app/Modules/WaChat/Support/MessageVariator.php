<?php

namespace App\Modules\WaChat\Support;

/**
 * Makes each outgoing text different from the one before it, so WhatsApp's bulk-message detection does not see
 * the same text going to many people:
 *  - rotates through the message variations (the main text + extras) in shuffled rounds, so all get used evenly;
 *  - puts one of the shared numbers into the text ({{share_number}}, or a "Contact:" line when the text has no
 *    placeholder), also in shuffled rounds so the same number never comes up twice in a row;
 *  - ends with a line of "......" that grows with each message: 1 line, 2, 3, then back to 1 and round again.
 * Everything is derived from the message's position, so a resumed campaign carries on the same pattern.
 */
final class MessageVariator
{
    public const DOTS = '......';

    private array $orders = [];

    public function __construct(
        private readonly array $variants,
        private readonly array $shareNumbers,
        private readonly bool $dots,
        private readonly int $seed = 0,
    ) {}

    public static function fromOptions(?array $variation, int $seed): ?self
    {
        if (!$variation || empty($variation['enabled'])) return null;
        return new self((array) ($variation['variants'] ?? []), (array) ($variation['share_numbers'] ?? []), (bool) ($variation['dots'] ?? true), $seed);
    }

    /** @param bool $useVariants false for media captions / blocks, where the user's own wording must stay */
    public function compose(string $main, int $seq, bool $useVariants = true): string
    {
        $pool = $useVariants ? array_values(array_filter([$main, ...$this->variants], fn ($t) => trim((string) $t) !== '')) : [$main];
        $text = $pool[$this->pick(count($pool), $seq, 1)] ?? $main;

        $share = $this->shareNumbers ? $this->shareNumbers[$this->pick(count($this->shareNumbers), $seq, 2)] : '';
        if (str_contains($text, '{{share_number}}')) {
            $text = str_replace('{{share_number}}', $share, $text);
        } elseif ($share !== '') {
            $text = rtrim($text) . "\n\nContact: " . $share;
        }

        if ($this->dots) {
            $text = rtrim($text) . "\n" . implode("\n", array_fill(0, self::dotLines($seq), self::DOTS));
        }
        return $text;
    }

    /** 1, 2, 3, 1, 2, 3 … */
    public static function dotLines(int $seq): int
    {
        return $seq % 3 + 1;
    }

    /** Index into a list of $n items for message number $seq: shuffled rounds, never the same item twice in a row. */
    public function pick(int $n, int $seq, int $salt): int
    {
        if ($n <= 1) return 0;
        return $this->order($n, intdiv($seq, $n), $salt)[$seq % $n];
    }

    private function order(int $n, int $round, int $salt): array
    {
        $key = "$n:$round:$salt";
        if (isset($this->orders[$key])) return $this->orders[$key];

        $idx = range(0, $n - 1);
        usort($idx, fn ($a, $b) => crc32("{$this->seed}:$salt:$round:$a") <=> crc32("{$this->seed}:$salt:$round:$b"));
        if ($round > 0 && $idx[0] === $this->order($n, $round - 1, $salt)[$n - 1]) {
            [$idx[0], $idx[1]] = [$idx[1], $idx[0]];       // the last of the previous round must not open this one
        }
        return $this->orders[$key] = $idx;
    }
}
