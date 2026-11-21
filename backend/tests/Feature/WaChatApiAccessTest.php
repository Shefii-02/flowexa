<?php

namespace Tests\Feature;

use App\Modules\WaChat\Models\WaOtpService;
use App\Modules\WaChat\Support\ApiAccess;
use App\Modules\WaChat\Support\ApiTemplateVariables;
use Illuminate\Http\Request;
use Tests\TestCase;

/** Who may call the public WA Chat API (allowed websites, curl/Postman/terminal switch) and the "variables must match the template" check. */
class WaChatApiAccessTest extends TestCase
{
    private const BROWSER = 'Mozilla/5.0 (Macintosh) AppleWebKit/537 Chrome/120 Safari/537';

    private function denial(array $domains, bool $allowTools, ?string $origin, string $agent): ?string
    {
        $server = ['HTTP_USER_AGENT' => $agent] + ($origin !== null ? ['HTTP_ORIGIN' => $origin] : []);
        $request = Request::create('/x', 'POST', [], [], [], $server);
        return ApiAccess::denial($request, new WaOtpService(['allowed_domains' => $domains, 'allow_non_browser' => $allowTools]))[0] ?? null;
    }

    public function test_with_no_rules_everything_is_allowed(): void
    {
        $this->assertNull($this->denial([], true, 'https://a.com', self::BROWSER));
        $this->assertNull($this->denial([], true, null, 'curl/8.4.0'));
    }

    public function test_switching_tools_off_refuses_curl_postman_and_calls_without_an_origin(): void
    {
        $this->assertSame('cli_not_allowed', $this->denial([], false, null, 'curl/8.4.0'));
        $this->assertSame('cli_not_allowed', $this->denial([], false, null, self::BROWSER), 'a call with no Origin is not a browser call');
        foreach (['PostmanRuntime/7.36.0', 'insomnia/2023', 'HTTPie/3.2', 'Wget/1.21', 'python-requests/2.31', 'curl/8.4.0'] as $agent) {
            $this->assertSame('cli_not_allowed', $this->denial([], false, 'https://a.com', $agent), "$agent faking an Origin must still be refused");
        }
        $this->assertNull($this->denial([], false, 'https://a.com', self::BROWSER), 'a real browser still works');
    }

    public function test_listed_websites_match_the_host_and_its_subdomains_only(): void
    {
        $d = ['https://shop.com'];
        $this->assertNull($this->denial($d, true, 'https://shop.com', self::BROWSER));
        $this->assertNull($this->denial($d, true, 'https://app.shop.com', self::BROWSER));
        $this->assertSame('origin_not_allowed', $this->denial($d, true, 'https://evilshop.com', self::BROWSER), 'a look-alike must not pass (the old check was a plain str_ends_with)');
        $this->assertSame('origin_not_allowed', $this->denial($d, true, 'https://shop.com.evil.io', self::BROWSER));
        $this->assertSame('origin_not_allowed', $this->denial($d, true, 'https://other.com', self::BROWSER));
        $this->assertNull($this->denial(['shop.com'], true, 'https://shop.com', self::BROWSER));
        $this->assertNull($this->denial(['https://shop.com/'], true, 'https://shop.com', self::BROWSER));
    }

    public function test_the_tools_switch_decides_server_side_calls_even_when_websites_are_listed(): void
    {
        $this->assertNull($this->denial(['https://shop.com'], true, null, 'curl/8.4.0'));
        $this->assertSame('cli_not_allowed', $this->denial(['https://shop.com'], false, null, 'curl/8.4.0'));
    }

    public function test_only_what_the_caller_must_send_is_expected(): void
    {
        $c = ApiTemplateVariables::check('Hi {{name}}, order {{ order_id }} is {{status}} — {{company}} {{otp}} {{expiry}}', ['name' => 'Ann', 'ORDER_ID' => '5', 'status' => 'ok']);
        $this->assertSame(['name', 'order_id', 'status'], $c['expected']);
        $this->assertTrue($c['ok']);
    }

    public function test_missing_empty_and_extra_variables(): void
    {
        $m = ApiTemplateVariables::check('Hi {{name}} {{order_id}}', ['name' => 'Ann']);
        $this->assertFalse($m['ok']);
        $this->assertSame(['order_id'], $m['missing']);
        $this->assertSame(['name'], ApiTemplateVariables::check('Hi {{name}}', ['name' => '  '])['missing'], 'an empty value is missing');
        $x = ApiTemplateVariables::check('Hi {{name}}', ['name' => 'A', 'nmae' => 'typo', 'otp' => '1']);
        $this->assertTrue($x['ok']);
        $this->assertSame(['nmae'], $x['ignored'], 'a typo is reported, the automatic tokens are not');
        $this->assertTrue(ApiTemplateVariables::check('Hello there', [])['ok']);
    }

    private function req(array $o = []): Request
    {
        $server = array_filter(['REMOTE_ADDR' => $o['remote'] ?? '10.0.0.1', 'HTTP_X_FORWARDED_FOR' => $o['xff'] ?? null, 'HTTP_X_TIMESTAMP' => $o['ts'] ?? null, 'HTTP_X_SIGNATURE' => $o['sig'] ?? null], fn ($v) => $v !== null);
        return Request::create('/x', 'POST', [], [], [], $server, $o['body'] ?? '{}');
    }

    public function test_the_client_ip_ignores_what_the_caller_writes_in_x_forwarded_for(): void
    {
        config(['services.open_wa.client_ip_hops' => 1]);
        $this->assertSame('203.0.113.5', ApiAccess::clientIp($this->req(['xff' => '1.2.3.4, 203.0.113.5'])), 'forged left entries must not win');
        $this->assertSame('10.0.0.1', ApiAccess::clientIp($this->req()), 'no header → the connection address');
        config(['services.open_wa.client_ip_hops' => 2]);
        $this->assertSame('203.0.113.5', ApiAccess::clientIp($this->req(['remote' => '127.0.0.1', 'xff' => '9.9.9.9, 203.0.113.5, 172.16.0.2'])));
        config(['services.open_wa.client_ip_hops' => 0]);
        $this->assertSame('198.51.100.9', ApiAccess::clientIp($this->req(['remote' => '198.51.100.9', 'xff' => '1.1.1.1'])), 'no proxy: a forged header is ignored');
    }

    public function test_the_ip_allowlist(): void
    {
        config(['services.open_wa.client_ip_hops' => 1]);
        $svc = new WaOtpService(['allowed_ips' => ['203.0.113.0/24', '198.51.100.7']]);
        $this->assertNull(ApiAccess::ipDenial($this->req(['xff' => '203.0.113.77']), $svc));
        $this->assertNull(ApiAccess::ipDenial($this->req(['xff' => '198.51.100.7']), $svc));
        $this->assertSame('ip_not_allowed', ApiAccess::ipDenial($this->req(['xff' => '8.8.8.8']), $svc)[0]);
        $this->assertSame('ip_not_allowed', ApiAccess::ipDenial($this->req(['xff' => '203.0.113.9, 8.8.8.8']), $svc)[0], 'a forged allowed address in the header does not help');
        $this->assertNull(ApiAccess::ipDenial($this->req(['xff' => '8.8.8.8']), new WaOtpService(['allowed_ips' => []])));
        foreach (['203.0.113.7', '203.0.113.0/24', '2001:db8::/32'] as $ok) { $this->assertTrue(ApiAccess::validIpEntry($ok), $ok); }
        foreach (['abc', '203.0.113.0/33', '300.1.1.1', '1.2.3'] as $bad) { $this->assertFalse(ApiAccess::validIpEntry($bad), $bad); }
    }

    public function test_signed_requests(): void
    {
        $svc = new WaOtpService(['require_signature' => true, 'signing_secret' => 'sec']);
        $svc->id = 4242;
        $body = '{"phone":"919876543210"}';
        $now  = time();
        $sig  = fn (int $ts, ?string $b = null, string $k = 'sec') => ApiAccess::sign($k, $ts, $b ?? $body);
        $call = fn (int $ts, string $signature, ?string $b = null) => ApiAccess::signatureDenial($this->req(['ts' => (string) $ts, 'sig' => $signature, 'body' => $b ?? $body]), $svc)[0] ?? null;

        $this->assertNull($call($now, $sig($now)));
        $this->assertSame('replayed_request', $call($now, $sig($now)), 'a signature works once');
        $this->assertSame('bad_signature', $call($now + 1, $sig($now + 1), '{"phone":"1"}'), 'a changed body breaks it');
        $this->assertSame('bad_signature', $call($now + 2, $sig($now + 2, null, 'wrong')));
        $this->assertSame('stale_timestamp', $call($now - 3600, $sig($now - 3600)));
        $this->assertSame('bad_signature', ApiAccess::signatureDenial($this->req(), $svc)[0]);
        $this->assertSame('signature_not_configured', ApiAccess::signatureDenial($this->req(), new WaOtpService(['require_signature' => true]))[0], 'fails closed without a secret');
        // PHP and the Node/Python samples in the guide must agree on the algorithm
        $this->assertSame('7850d6a6cd9cd4fd028da5b57c2ab57ceff077f77209440ab7ac16d59f7683f7', ApiAccess::sign('s3cret-abc123', 1700000000, '{"phone":"919876543210","template_id":14}'));
    }

    public function test_a_signed_call_skips_the_header_rules_but_not_the_ip_list(): void
    {
        config(['services.open_wa.client_ip_hops' => 1]);
        $svc = new WaOtpService(['require_signature' => true, 'signing_secret' => 'sec', 'allow_non_browser' => false, 'allowed_ips' => ['203.0.113.0/24']]);
        $svc->id = 4343;
        $signed = fn (string $xff, int $ts) => $this->req(['xff' => $xff, 'ts' => (string) $ts, 'sig' => ApiAccess::sign('sec', $ts, '{}')]);
        $this->assertNull(ApiAccess::check($signed('203.0.113.5', time() + 50), $svc), 'signed + right IP passes although tools are switched off');
        $this->assertSame('ip_not_allowed', ApiAccess::check($signed('8.8.8.8', time() + 51), $svc)[1], 'signed but wrong IP is still refused');
        $this->assertSame(401, ApiAccess::check($this->req(['xff' => '203.0.113.5']), $svc)[0], 'unsigned is refused 401');
    }

    public function test_rate_limits(): void
    {
        $svc = new WaOtpService(['rate_per_minute' => 2, 'max_per_phone_hour' => 2]);
        $svc->id = 4545;
        \Illuminate\Support\Facades\RateLimiter::clear('wa-api:4545:min');
        $this->assertNull(ApiAccess::throttle($svc));
        $this->assertNull(ApiAccess::throttle($svc));
        $this->assertSame('rate_limited', ApiAccess::throttle($svc)[1]);
        $this->assertNull(ApiAccess::throttlePhone($svc, '+91 99000 00001'));
        $this->assertNull(ApiAccess::throttlePhone($svc, '919900000001'));
        $this->assertSame('phone_limit', ApiAccess::throttlePhone($svc, '919900000001')[1], 'the same number written two ways counts once');
        $this->assertNull(ApiAccess::throttlePhone($svc, '919900000002'));
        $free = new WaOtpService(['rate_per_minute' => 0, 'max_per_phone_hour' => 0]);
        $free->id = 4646;
        for ($i = 0; $i < 50; $i++) { $this->assertNull(ApiAccess::throttle($free)); $this->assertNull(ApiAccess::throttlePhone($free, '919900000003')); }
    }

    public function test_every_variable_needs_a_usable_value_and_the_message_names_it(): void
    {
        $f = fn (string $tpl, array $vars) => ApiTemplateVariables::failure(ApiTemplateVariables::check($tpl, $vars), 14, 'Order update');
        $this->assertSame('The variable "order_id" is missing for this template (Order update).', $f('Hi {{name}} {{order_id}}', ['name' => 'Ann'])['error']);
        $this->assertSame('The variables "a", "b" are missing for this template (Order update).', $f('{{a}} {{b}}', [])['error']);
        $x = $f('Hi {{name}} {{order_id}}', ['name' => 'Ann']);
        $this->assertSame(['order_id'], $x['missing']);
        $this->assertSame(['variables.order_id' => ['The variable "order_id" is missing for this template (Order update).']], (array) $x['errors']);
        $this->assertSame(['a', 'b', 'c'], ApiTemplateVariables::check('{{a}} {{b}} {{c}}', ['a' => '', 'b' => '   ', 'c' => null])['missing']);
        $this->assertTrue(ApiTemplateVariables::check('{{q}} {{z}}', ['q' => 0, 'z' => '0'])['ok'], '0 is a real value');
        $bad = ApiTemplateVariables::check('Hi {{name}}', ['name' => ['x']]);
        $this->assertFalse($bad['ok']);
        $this->assertSame(['name' => 'must be text or a number'], $bad['invalid']);
        $this->assertSame(['n' => 'must be text or a number'], ApiTemplateVariables::check('{{n}}', ['n' => true])['invalid']);
        $this->assertSame(['name' => 'is too long (max 1000 characters)'], ApiTemplateVariables::check('{{name}}', ['name' => str_repeat('a', 1001)])['invalid']);
        $this->assertTrue(ApiTemplateVariables::check('{{name}}', ['name' => str_repeat('a', 1000)])['ok']);
        $this->assertTrue(ApiTemplateVariables::check('Hi {{name}}', ['name' => 'A', 'extra' => ['x']])['ok'], 'junk for a variable the template does not use is ignored');
    }
}
