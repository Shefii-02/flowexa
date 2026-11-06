<?php

namespace App\Modules\Instagram\Auth;

/** Parses Meta's `signed_request` (deauthorize / data-deletion callbacks): base64url(sig).base64url(payload), HMAC-SHA256 with the app secret. */
class SignedRequest
{
    public static function parse(?string $signed, ?string $secret): ?array
    {
        if (!$signed || !$secret || substr_count($signed, '.') !== 1) {
            return null;
        }
        [$sig, $payload] = explode('.', $signed, 2);
        $expected = hash_hmac('sha256', $payload, $secret, true);
        $given    = self::b64($sig);
        if ($given === false || !hash_equals($expected, $given)) {
            return null;
        }
        $data = json_decode((string) self::b64($payload), true);
        return is_array($data) ? $data : null;
    }

    private static function b64(string $s): string|false
    {
        return base64_decode(strtr($s, '-_', '+/'), true);
    }
}
