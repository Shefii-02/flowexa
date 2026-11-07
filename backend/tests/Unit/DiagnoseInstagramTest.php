<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DiagnoseInstagramTest extends TestCase
{
    #[Test]
    public function bad_instagram_secret_is_reported(): void
    {
        config(['services.instagram.ig_app_secret' => 'wrongsecret1234', 'services.messenger.app_secret' => 'metasecret9999']);
        Http::fake([
            'api.instagram.com/*' => Http::response(['error_type' => 'OAuthException', 'error_message' => 'Invalid client secret'], 400),
            'graph.facebook.com/*' => Http::response(['access_token' => 'x|y']),
        ]);
        $this->artisan('instagram:diagnose')->expectsOutputToContain('rejected IG_APP_SECRET')->assertFailed();
    }

    #[Test]
    public function good_credentials_only_complain_about_the_fake_code(): void
    {
        config(['services.instagram.ig_app_secret' => 'goodsecret5678', 'services.messenger.app_secret' => 'metasecret9999']);
        Http::fake([
            'api.instagram.com/*' => Http::response(['error_type' => 'OAuthException', 'error_message' => 'Invalid authorization code'], 400),
            'graph.facebook.com/*' => Http::response(['access_token' => 'x|y']),
        ]);
        $this->artisan('instagram:diagnose')->expectsOutputToContain('accepted the app id, secret and redirect URI')->assertSuccessful();
    }
}
