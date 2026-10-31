<?php

namespace Tests\Unit;

use App\Modules\WaChat\Services\OpenWaMessageService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OpenWaMediaSendTest extends TestCase
{
    // a real 1x1 PNG
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    public function test_a_file_on_our_own_disk_is_sent_even_when_its_stored_link_has_an_old_domain(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('media/photo.png', base64_decode(self::PNG));
        Http::fake(['http://gateway.test/*' => Http::response(['messageId' => 'ok'], 201)]);

        $res = (new OpenWaMessageService('http://gateway.test/api'))->sendMediaFromUrl(
            'sess', 'key', '919876543210@c.us', 'image', 'https://old-domain.example/storage/media/photo.png', ['caption' => 'Hi']
        );

        $this->assertTrue($res->successful());
        Http::assertSentCount(1);                                                    // nothing was requested from the dead domain
        Http::assertSent(function (Request $r) {
            return $r->url() === 'http://gateway.test/api/sessions/sess/messages/send-image'
                && $r['base64'] === self::PNG && $r['mimetype'] === 'image/png' && $r['caption'] === 'Hi' && $r['chatId'] === '919876543210@c.us';
        });
    }

    public function test_when_the_server_cannot_download_a_public_link_the_gateway_is_asked_to_fetch_it(): void
    {
        Storage::fake('public');
        Http::fake([
            'https://cdn.example/*'  => Http::response('nope', 403),
            'http://gateway.test/*'  => Http::response(['messageId' => 'ok'], 201),
        ]);

        $res = (new OpenWaMessageService('http://gateway.test/api'))->sendMediaFromUrl('sess', 'key', '1@c.us', 'image', 'https://cdn.example/a.jpg');

        $this->assertTrue($res->successful());
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'send-image') && $r['url'] === 'https://cdn.example/a.jpg' && !isset($r['base64']));
    }

    public function test_a_web_page_instead_of_an_image_is_still_reported_clearly(): void
    {
        Storage::fake('public');
        Http::fake(['https://site.example/*' => Http::response('<html></html>', 200, ['Content-Type' => 'text/html'])]);

        $this->expectExceptionMessage('points to a web page');
        (new OpenWaMessageService('http://gateway.test/api'))->sendMediaFromUrl('sess', 'key', '1@c.us', 'image', 'https://site.example/page');
    }

    public function test_path_tricks_cannot_read_files_outside_the_public_disk(): void
    {
        Storage::fake('public');
        Http::fake(['*' => Http::response('x', 404)]);

        $this->expectException(\RuntimeException::class);
        (new OpenWaMessageService('http://gateway.test/api'))->sendMediaFromUrl('sess', 'key', '1@c.us', 'document', '/storage/../.env');
    }
}
