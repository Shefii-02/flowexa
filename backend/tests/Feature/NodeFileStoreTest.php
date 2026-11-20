<?php

namespace Tests\Feature;

use App\Support\NodeFileStore;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** WA Chat files are hosted by the Node gateway and shared from there; this server's disk is only the fallback. */
class NodeFileStoreTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.open_wa.base_url' => 'https://chatwa.test/api', 'services.open_wa.admin_key' => 'admin', 'services.open_wa.files_on_node' => true]);
        Storage::fake('public');
    }

    public function test_a_file_is_uploaded_to_the_gateway_and_its_public_url_is_returned(): void
    {
        Http::fake(['chatwa.test/api/files' => Http::response(['key' => 'files/9/invoices/x.pdf', 'url' => 'https://chatwa.test/api/files/public/9/invoices/aaaaaaaa-1.pdf'], 201)]);
        $url = NodeFileStore::put('%PDF-1', 9, 'invoices', 'Invoice 7.pdf', 'application/pdf');

        $this->assertSame('https://chatwa.test/api/files/public/9/invoices/aaaaaaaa-1.pdf', $url);
        Storage::disk('public')->assertDirectoryEmpty('/');   // nothing is kept on this server
        Http::assertSent(function (Request $r) {
            return $r->method() === 'POST' && $r->url() === 'https://chatwa.test/api/files' && $r->hasHeader('X-API-Key', 'admin')
                && $r['companyId'] === 9 && $r['folder'] === 'invoices' && $r['filename'] === 'Invoice 7.pdf'
                && $r['mimetype'] === 'application/pdf' && base64_decode($r['data']) === '%PDF-1';
        });
    }

    public function test_mime_is_inferred_from_the_extension_when_not_given(): void
    {
        Http::fake(['chatwa.test/*' => Http::response(['url' => 'https://chatwa.test/api/files/public/1/voice-replies/bbbbbbbb-2.mp3'], 201)]);
        NodeFileStore::put('ID3', 1, 'voice-replies', 'reply.mp3');
        Http::assertSent(fn (Request $r) => $r['mimetype'] === 'audio/mpeg');
    }

    public function test_when_the_gateway_refuses_or_is_down_the_file_is_kept_locally_so_the_send_still_works(): void
    {
        Http::fake(['chatwa.test/*' => Http::response('nope', 500)]);
        $url = NodeFileStore::put('abc', 3, 'exports', 'r.csv');
        $this->assertStringContainsString('/storage/exports/3/', $url);
        $this->assertCount(1, Storage::disk('public')->allFiles('exports/3'));

        Http::fake(fn () => throw new ConnectionException('down'));
        $this->assertStringContainsString('/storage/exports/3/', NodeFileStore::put('abc', 3, 'exports', 'r2.csv'));
        $this->assertCount(2, Storage::disk('public')->allFiles('exports/3'));
    }

    public function test_it_can_be_switched_off_or_left_unconfigured(): void
    {
        Http::fake();
        config(['services.open_wa.files_on_node' => false]);
        $this->assertStringContainsString('/storage/', NodeFileStore::put('x', 1, 'invoices', 'a.pdf'));
        config(['services.open_wa.files_on_node' => true, 'services.open_wa.admin_key' => null]);
        $this->assertStringContainsString('/storage/', NodeFileStore::put('x', 1, 'invoices', 'a.pdf'));
        Http::assertNothingSent();
    }

    public function test_deleting_a_hosted_url_asks_the_gateway_to_delete_its_key(): void
    {
        Http::fake(['chatwa.test/*' => Http::response(['deleted' => true], 200)]);
        NodeFileStore::delete('https://chatwa.test/api/files/public/9/exports/aaaaaaaa-1.csv');
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r->url() === 'https://chatwa.test/api/files?key=files%2F9%2Fexports%2Faaaaaaaa-1.csv' && $r->hasHeader('X-API-Key', 'admin'));
    }

    public function test_deleting_an_older_local_url_removes_the_file_from_this_servers_disk(): void
    {
        Storage::disk('public')->put('exports/old.csv', 'x');
        Http::fake();
        NodeFileStore::delete('https://old-domain.example/storage/exports/old.csv');
        Storage::disk('public')->assertMissing('exports/old.csv');
        Http::assertNothingSent();
    }

    public function test_delete_never_throws_and_ignores_anything_unsafe_or_foreign(): void
    {
        Storage::disk('public')->put('keep.txt', 'x');
        Http::fake(fn () => throw new ConnectionException('down'));
        NodeFileStore::delete('https://chatwa.test/api/files/public/9/exports/aaaaaaaa-1.csv');   // gateway down: no exception
        NodeFileStore::delete('https://x.test/storage/../keep.txt');
        NodeFileStore::delete('https://elsewhere.test/file.pdf');
        NodeFileStore::delete(null);
        NodeFileStore::delete('');
        Storage::disk('public')->assertExists('keep.txt');
    }
}
