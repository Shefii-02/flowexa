<?php

namespace App\Modules\WaChat\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Platform-wide rules for how fast a message-sender campaign may go, managed by the superadmin
 * (platform_settings['wa_sender_limits']). A company picks its own speed, but only inside these bounds.
 */
class SenderLimits
{
    public const KEY = 'wa_sender_limits';

    public const DEFAULTS = [
        'default_per_min_min' => 2,    // a new campaign sends 2–3 messages every minute...
        'default_per_min_max' => 3,
        'default_per_5min_cap' => 15,  // ...and never more than this many in any 5 minutes
        'max_per_min'         => 100,  // highest "messages per minute" a company may choose
        'min_per_5min_cap'    => 10,   // the 5-minute cap must stay between these two
        'max_per_5min_cap'    => 500,
        'min_share_numbers'   => 3,    // numbers rotated into the message text (anti-block variation)
        'max_variants'        => 10,
    ];

    public static function get(): array
    {
        $raw = DB::table('platform_settings')->where('key', self::KEY)->value('value');
        $saved = is_string($raw) ? (json_decode($raw, true) ?: []) : [];
        return self::sanitize(array_merge(self::DEFAULTS, array_intersect_key($saved, self::DEFAULTS)));
    }

    public static function save(array $input): array
    {
        $limits = self::sanitize(array_merge(self::get(), array_intersect_key($input, self::DEFAULTS)));
        DB::table('platform_settings')->updateOrInsert(['key' => self::KEY], ['value' => json_encode($limits), 'updated_at' => now(), 'created_at' => now()]);
        return $limits;
    }

    /** Keeps the admin's numbers consistent with each other (min <= default <= max). */
    public static function sanitize(array $l): array
    {
        $l = array_map('intval', $l);
        $l['max_per_min']         = max(1, min(600, $l['max_per_min']));
        $l['min_per_5min_cap']    = max(1, $l['min_per_5min_cap']);
        $l['max_per_5min_cap']    = max($l['min_per_5min_cap'], min(3000, $l['max_per_5min_cap']));
        $l['default_per_min_min'] = max(1, min($l['max_per_min'], $l['default_per_min_min']));
        $l['default_per_min_max'] = max($l['default_per_min_min'], min($l['max_per_min'], $l['default_per_min_max']));
        $l['default_per_5min_cap'] = max($l['min_per_5min_cap'], min($l['max_per_5min_cap'], $l['default_per_5min_cap']));
        $l['min_share_numbers']   = max(1, min(20, $l['min_share_numbers']));
        $l['max_variants']        = max(1, min(30, $l['max_variants']));
        return $l;
    }

    /**
     * Turns what the sender page posted into the options stored on the job, or throws a 422 that says what to fix.
     * The delivery speed always comes from the platform settings (superadmin-managed); only the message variation is the company's.
     */
    public static function normalize(?array $in, ?array $limits = null): array
    {
        $L = $limits ?? self::get();
        $in = $in ?? [];
        $fail = fn (string $field, string $msg) => throw ValidationException::withMessages(["sending_options.$field" => $msg]);

        // Speed is the superadmin's call: whatever a client sends for pacing is ignored.
        $min = $L['default_per_min_min'];
        $max = $L['default_per_min_max'];
        $cap = $L['default_per_5min_cap'];

        $v = $in['variation'] ?? [];
        $enabled = (bool) ($v['enabled'] ?? false);
        $variants = array_values(array_filter(array_map(fn ($t) => trim((string) $t), (array) ($v['variants'] ?? [])), fn ($t) => $t !== ''));
        $shares = [];
        foreach ((array) ($v['share_numbers'] ?? []) as $n) {
            $n = trim((string) $n);
            if ($n === '') continue;
            $digits = preg_replace('/\D+/', '', $n);
            if (strlen($digits) < 7 || strlen($digits) > 15) $fail('variation', "“{$n}” is not a valid phone number to share.");
            $shares[$digits] = $n;                      // de-duplicated by digits, shown as typed
        }
        $shares = array_values($shares);

        if (count($variants) > $L['max_variants'] - 1) $fail('variation', 'You can add at most ' . ($L['max_variants'] - 1) . ' extra message variations.');
        foreach ($variants as $t) if (mb_strlen($t) > 4000) $fail('variation', 'A message variation is too long (4000 characters max).');
        if ($enabled && count($shares) < $L['min_share_numbers']) {
            $fail('variation', "Add at least {$L['min_share_numbers']} different numbers to share — they are rotated through the messages.");
        }

        return [
            'pacing'    => ['per_min_min' => $min, 'per_min_max' => $max, 'per_5min_cap' => $cap],
            'variation' => ['enabled' => $enabled, 'variants' => $enabled ? $variants : [], 'share_numbers' => $enabled ? $shares : [], 'dots' => $enabled && (bool) ($v['dots'] ?? true)],
        ];
    }
}
