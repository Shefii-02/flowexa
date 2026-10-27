<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Text/number captcha for the web login form. The answer lives only in the cache (never sent to the
 * client), expires after TTL seconds and is single-use — see self::verify().
 */
class CaptchaController extends Controller
{
    public const TTL = 60;
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no 0/O/1/I

    // ─── GET /auth/captcha ────────────────────────────────────────────────────
    public function show(): JsonResponse
    {
        $code = '';
        for ($i = 0; $i < 5; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        $id = (string) Str::uuid();
        Cache::put("captcha:$id", $code, self::TTL + 5); // small grace for network latency

        return response()->json([
            'captcha_id' => $id,
            'image'      => 'data:image/svg+xml;base64,' . base64_encode($this->svg($code)),
            'expires_in' => self::TTL,
        ])->header('Cache-Control', 'no-store');
    }

    /** Single-use, case-insensitive check. The challenge is consumed whether or not it matches. */
    public static function verify(?string $id, ?string $answer): bool
    {
        if (!$id || !$answer) return false;
        $expected = Cache::pull("captcha:$id");
        return is_string($expected) && hash_equals($expected, strtoupper(trim($answer)));
    }

    private function svg(string $code): string
    {
        $w = 190; $h = 56;
        $out = "<svg xmlns='http://www.w3.org/2000/svg' width='$w' height='$h' viewBox='0 0 $w $h'>"
             . "<rect width='$w' height='$h' fill='#f1f5f9'/>";

        for ($i = 0; $i < 6; $i++) { // noise lines
            $c = sprintf('#%02x%02x%02x', random_int(120, 200), random_int(120, 200), random_int(120, 200));
            $out .= sprintf("<path d='M%d %d Q%d %d %d %d' stroke='%s' stroke-width='1.4' fill='none'/>",
                random_int(0, 30), random_int(5, $h - 5), random_int(60, 130), random_int(0, $h),
                random_int($w - 30, $w), random_int(5, $h - 5), $c);
        }
        for ($i = 0; $i < 24; $i++) { // noise dots
            $out .= sprintf("<circle cx='%d' cy='%d' r='1.2' fill='#94a3b8'/>", random_int(0, $w), random_int(0, $h));
        }

        $step = ($w - 30) / strlen($code);
        foreach (str_split($code) as $i => $ch) {
            $x = 18 + $i * $step;
            $y = random_int(34, 42);
            $c = sprintf('#%02x%02x%02x', random_int(15, 90), random_int(30, 90), random_int(60, 140));
            $out .= sprintf(
                "<text x='%d' y='%d' font-family='Verdana,Arial,sans-serif' font-size='%d' font-weight='700' fill='%s' transform='rotate(%d %d %d)'>%s</text>",
                $x, $y, random_int(26, 32), $c, random_int(-22, 22), $x, $y, $ch
            );
        }
        return $out . '</svg>';
    }
}
