<?php

namespace Tests\Unit;

use App\Modules\Instagram\Auth\InstagramLoginProvider;
use App\Modules\Instagram\Auth\SignedRequest;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InstagramAuthTest extends TestCase
{
    private function sign(array $payload, string $secret): string
    {
        $b = fn ($s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $p = $b(json_encode($payload));
        return $b(hash_hmac('sha256', $p, $secret, true)) . '.' . $p;
    }

    #[Test]
    public function signed_request_accepts_valid_and_rejects_tampered(): void
    {
        $sr = $this->sign(['user_id' => '123'], 's3cret');
        $this->assertSame('123', SignedRequest::parse($sr, 's3cret')['user_id']);
        $this->assertNull(SignedRequest::parse($sr, 'wrong'));
        $this->assertNull(SignedRequest::parse('garbage', 's3cret'));
        $this->assertNull(SignedRequest::parse(null, 's3cret'));
    }

    #[Test]
    public function authorize_url_carries_scopes_and_state(): void
    {
        config(['services.instagram.app_id' => '3017013418646493', 'services.instagram.redirect_uri' => 'https://teamzo.io/instagram/accounts']);
        $url = (new InstagramLoginProvider)->authorizeUrl('abc');
        $this->assertStringStartsWith('https://www.instagram.com/oauth/authorize?', $url);
        $this->assertStringContainsString('instagram_business_manage_messages', urldecode($url));
        $this->assertStringContainsString('state=abc', $url);
    }

    #[Test]
    public function exchange_code_runs_short_long_profile_chain(): void
    {
        Http::fake([
            'api.instagram.com/*'                       => Http::response(['data' => [['access_token' => 'SHORT', 'user_id' => '1', 'permissions' => 'instagram_business_basic']]]),
            'graph.instagram.com/access_token*'         => Http::response(['access_token' => 'LONG', 'expires_in' => 5184000]),
            'graph.instagram.com/*/me*'                 => Http::response(['user_id' => '999', 'username' => 'acme', 'followers_count' => 5]),
        ]);
        $r = (new InstagramLoginProvider)->exchangeCode('CODE#_');
        $this->assertSame('999', $r['ig_user_id']);
        $this->assertSame('LONG', $r['access_token']);
        $this->assertSame(['instagram_business_basic'], $r['scopes']);
    }

    #[Test]
    public function a_failing_step_is_named_in_the_error(): void
    {
        Http::fake([
            'api.instagram.com/*'                    => Http::response(['data' => [['access_token' => 'SHORT', 'user_id' => '1']]]),
            'graph.instagram.com/*access_token*'     => Http::response(['error' => ['message' => 'Unsupported request - method type: get', 'code' => 100]], 400),
        ]);
        $this->expectExceptionMessageMatches('/Unsupported request.*\(during long-lived token\)/');
        (new InstagramLoginProvider)->exchangeCode('CODE');
    }

    #[Test]
    public function long_lived_exchange_falls_back_to_the_other_endpoint_form(): void
    {
        Http::fake([
            'api.instagram.com/*'                        => Http::response(['data' => [['access_token' => 'SHORT', 'user_id' => '1']]]),
            'graph.instagram.com/v25.0/access_token*'    => Http::response(['error' => ['message' => 'Unsupported request - method type: get', 'code' => 100]], 400),
            'graph.instagram.com/access_token*'          => Http::response(['access_token' => 'LONG', 'expires_in' => 5184000]),
            'graph.instagram.com/*/me*'                  => Http::response(['user_id' => '999', 'username' => 'acme']),
        ]);
        $this->assertSame('LONG', (new InstagramLoginProvider)->exchangeCode('CODE')['access_token']);
    }
}
