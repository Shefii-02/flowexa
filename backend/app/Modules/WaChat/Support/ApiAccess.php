<?php

namespace App\Modules\WaChat\Support;

use App\Modules\WaChat\Models\WaOtpService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Who may call the public WA Chat API: allowed websites (the Origin header a browser sends) and whether curl / Postman / the terminal are allowed.
 *
 * Two layers, and it matters which is which:
 *  - Header rules (Origin, User-Agent): the caller writes those headers, so anyone holding the token can fake them. They only stop casual use.
 *  - Real controls the caller cannot fake: the network address (allowed IPs), a request signature made with a secret that never travels
 *    (HMAC), and rate limits that cap the damage if the token does leak.
 */
class ApiAccess
{
    /** Command-line / API-testing tools. A browser never sends one of these. */
    private const TOOL_AGENTS = '/(curl|postmanruntime|postman|insomnia|httpie|wget|python-requests|go-http-client|libwww-perl|powershell)/i';

    /** Signature freshness window (seconds) — also how long a used signature is remembered so it cannot be replayed. */
    public const SIGNATURE_WINDOW = 300;

    /**
     * The whole gate, in order: signature (if the service requires one) → allowed IPs → header rules (skipped for a validly signed call,
     * which already proved the caller holds the secret). Returns [httpStatus, code, message] or null.
     */
    public static function check(Request $request, WaOtpService $service): ?array
    {
        $signed = false;
        if ($service->require_signature) {
            if ($d = self::signatureDenial($request, $service)) { return [401, $d[0], $d[1]]; }
            $signed = true;
        }
        if ($d = self::ipDenial($request, $service)) { return [403, $d[0], $d[1]]; }
        if (!$signed && ($d = self::denial($request, $service))) { return [403, $d[0], $d[1]]; }
        return null;
    }

    /**
     * The caller's address. X-Forwarded-For is written by the caller too, so only the entries added by OUR proxies count: the chain is
     * [X-Forwarded-For entries…, REMOTE_ADDR] and the client is `hops` places from the right (services.open_wa.client_ip_hops; 0 = no proxy).
     */
    public static function clientIp(Request $request): string
    {
        $remote = (string) $request->server('REMOTE_ADDR', '');
        $hops   = max(0, (int) config('services.open_wa.client_ip_hops', 1));
        if ($hops === 0) { return $remote; }

        $chain = array_values(array_filter(array_map('trim', explode(',', (string) $request->server('HTTP_X_FORWARDED_FOR', '')))));
        $chain[] = $remote;
        $at = count($chain) - 1 - $hops;
        $ip = $at >= 0 ? $chain[$at] : $remote;
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : $remote;
    }

    /** [code, message] when the caller's IP is not on the allowlist (an empty list = any IP). */
    public static function ipDenial(Request $request, WaOtpService $service): ?array
    {
        $list = array_values(array_filter(array_map('trim', (array) ($service->allowed_ips ?? []))));
        if (!$list) { return null; }
        $ip = self::clientIp($request);
        return ($ip !== '' && IpUtils::checkIp($ip, $list)) ? null : ['ip_not_allowed', 'Your IP address is not allowed to call this API.'];
    }

    /** An IPv4 / IPv6 address or a CIDR range such as 203.0.113.0/24. */
    public static function validIpEntry(string $entry): bool
    {
        $entry = trim($entry);
        if (!str_contains($entry, '/')) { return (bool) filter_var($entry, FILTER_VALIDATE_IP); }
        [$ip, $bits] = explode('/', $entry, 2);
        $v6 = str_contains($ip, ':');
        return (bool) filter_var($ip, FILTER_VALIDATE_IP) && ctype_digit($bits) && (int) $bits <= ($v6 ? 128 : 32);
    }

    /** The signature a client must send: hex HMAC-SHA256 of "{X-Timestamp}.{raw request body}" with the service's signing secret. */
    public static function sign(string $secret, int|string $timestamp, string $body): string
    {
        return hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    }

    /** [code, message] when a required signature is missing, wrong, stale or already used. */
    public static function signatureDenial(Request $request, WaOtpService $service): ?array
    {
        $secret = (string) ($service->signing_secret ?? '');
        if ($secret === '') { return ['signature_not_configured', 'This API requires signed requests but no signing secret has been generated yet.']; }

        $ts  = trim((string) $request->header('X-Timestamp', ''));
        $sig = strtolower(preg_replace('/^sha256=/i', '', trim((string) $request->header('X-Signature', ''))));
        if ($ts === '' || $sig === '') { return ['bad_signature', 'This API requires signed requests: send the X-Timestamp and X-Signature headers.']; }
        if (!ctype_digit($ts) || abs(time() - (int) $ts) > self::SIGNATURE_WINDOW) { return ['stale_timestamp', 'X-Timestamp is too old or too far ahead (allowed: ±5 minutes). Check your server clock.']; }
        if (!hash_equals(self::sign($secret, $ts, $request->getContent()), $sig)) { return ['bad_signature', 'The request signature is invalid.']; }
        // each signature is good once — a captured request cannot be sent again
        if (!Cache::add("wa-api-sig:{$service->id}:{$sig}", 1, self::SIGNATURE_WINDOW * 2)) { return ['replayed_request', 'This signed request was already used.']; }
        return null;
    }

    /** Calls per minute for the whole service — caps the damage if the token leaks. [429, code, message, retryAfter] or null; 0 = unlimited. */
    public static function throttle(WaOtpService $service): ?array
    {
        $limit = (int) ($service->rate_per_minute ?? 0);
        if ($limit <= 0) { return null; }
        $key = "wa-api:{$service->id}:min";
        if (RateLimiter::tooManyAttempts($key, $limit)) {
            return [429, 'rate_limited', "Too many requests: this API allows {$limit} per minute.", RateLimiter::availableIn($key)];
        }
        RateLimiter::hit($key, 60);
        return null;
    }

    /** Messages to ONE number per hour — stops someone being OTP-bombed. Counted only for requests that are about to send. */
    public static function throttlePhone(WaOtpService $service, string $phone): ?array
    {
        $limit  = (int) ($service->max_per_phone_hour ?? 0);
        $digits = preg_replace('/\D/', '', $phone);
        if ($limit <= 0 || $digits === '') { return null; }
        $key = "wa-api:{$service->id}:phone:{$digits}";
        if (RateLimiter::tooManyAttempts($key, $limit)) {
            return [429, 'phone_limit', "Too many messages to this number: the limit is {$limit} per hour.", RateLimiter::availableIn($key)];
        }
        RateLimiter::hit($key, 3600);
        return null;
    }

    /** [code, message] when the call must be refused, null when it may go ahead. */
    public static function denial(Request $request, WaOtpService $service): ?array
    {
        $origin = trim((string) $request->header('Origin', ''));
        $agent  = (string) $request->userAgent();

        if (!($service->allow_non_browser ?? true)) {
            if ($origin === '' || preg_match(self::TOOL_AGENTS, $agent)) {
                return ['cli_not_allowed', 'Calls from curl, Postman or the terminal are not allowed for this API. Call it from your website instead.'];
            }
        }

        $domains = array_values(array_filter(array_map('trim', (array) ($service->allowed_domains ?? []))));
        if ($origin !== '' && $domains && !self::originAllowed($origin, $domains)) {
            return ['origin_not_allowed', 'Origin not allowed.'];
        }
        // A server-side call has no Origin; with the allow switch on it is let through even when websites are listed (the list is for browsers).
        return null;
    }

    /** The Origin's host must be one of the listed hosts or a subdomain of one — "evilexample.com" does not match "example.com". */
    public static function originAllowed(string $origin, array $domains): bool
    {
        $host = self::host($origin);
        if ($host === '') { return false; }
        foreach ($domains as $d) {
            $allowed = self::host($d);
            if ($allowed !== '' && ($host === $allowed || str_ends_with($host, '.' . $allowed))) { return true; }
        }
        return false;
    }

    private static function host(string $value): string
    {
        $value = strtolower(trim($value));
        if ($value === '') { return ''; }
        $host = parse_url(str_contains($value, '://') ? $value : "//{$value}", PHP_URL_HOST);
        return is_string($host) ? $host : '';
    }
}
