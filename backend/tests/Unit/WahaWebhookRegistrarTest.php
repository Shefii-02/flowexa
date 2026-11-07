<?php

namespace Tests\Unit;

use App\Modules\WaChat\Models\WahaSession;
use App\Modules\WaChat\Services\WahaWebhookRegistrar;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WahaWebhookRegistrarTest extends TestCase
{
    private function waSession(): WahaSession { return new WahaSession(['session_name' => 'acme']); }

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.open_wa.base_url' => 'http://gw.test:2785/api', 'services.open_wa.admin_key' => 'adm', 'app.url' => 'http://api.teamzo.io']);
        \Illuminate\Support\Facades\URL::forceRootUrl('http://api.teamzo.io');
    }

    #[Test]
    public function a_public_http_app_url_is_registered_as_https(): void
    {
        $this->assertSame('https://api.teamzo.io/api/v1/waha/webhook', WahaWebhookRegistrar::targetUrl());
    }

    #[Test]
    public function local_hosts_keep_http(): void
    {
        \Illuminate\Support\Facades\URL::forceRootUrl('http://127.0.0.1:8000');
        $this->assertSame('http://127.0.0.1:8000/api/v1/waha/webhook', WahaWebhookRegistrar::targetUrl());
    }

    #[Test]
    public function a_stale_http_hook_is_repointed_in_place_not_duplicated(): void
    {
        Http::fake([
            '*/webhooks' => Http::sequence()->push([['id' => 'h1', 'url' => 'http://api.teamzo.io/api/v1/waha/webhook', 'events' => ['*']]]),
            '*/webhooks/h1' => Http::response(['ok' => true]),
        ]);
        $this->assertTrue((new WahaWebhookRegistrar)->ensure($this->waSession()));
        Http::assertSent(fn ($r) => $r->method() === 'PUT' && str_ends_with($r->url(), '/webhooks/h1')
            && $r['url'] === 'https://api.teamzo.io/api/v1/waha/webhook');
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
    }

    #[Test]
    public function a_correct_hook_is_left_alone_and_a_missing_one_is_created(): void
    {
        Http::fake(['*/webhooks' => Http::sequence()
            ->push([['id' => 'h1', 'url' => 'https://api.teamzo.io/api/v1/waha/webhook', 'events' => ['*']]])
            ->push([])
            ->push(['id' => 'new'])]);
        (new WahaWebhookRegistrar)->ensure($this->waSession());
        Http::assertNotSent(fn ($r) => in_array($r->method(), ['PUT', 'POST'], true));

        (new WahaWebhookRegistrar)->ensure($this->waSession());
        Http::assertSent(fn ($r) => $r->method() === 'POST' && $r['url'] === 'https://api.teamzo.io/api/v1/waha/webhook' && $r['events'] === ['*']);
    }
}
