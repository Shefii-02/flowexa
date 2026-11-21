<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Where WA Chat files live. They are hosted by the Node gateway (backend-node, `POST /api/files`) and shared from there by public URL, so
 * the Laravel server no longer stores or serves them. If the gateway cannot take a file right now (offline, not yet upgraded, no admin key),
 * the file is written to this server's public disk exactly as before, so a send never fails because of storage — and a URL from either place
 * keeps working and can be deleted through the same call.
 */
class NodeFileStore
{
    private const EXT_MIME = ['mp3' => 'audio/mpeg', 'ogg' => 'audio/ogg', 'opus' => 'audio/ogg', 'm4a' => 'audio/mp4', 'wav' => 'audio/wav', 'pdf' => 'application/pdf', 'csv' => 'text/csv'];

    public static function enabled(): bool
    {
        return (bool) config('services.open_wa.files_on_node', true) && filled(config('services.open_wa.base_url')) && filled(config('services.open_wa.admin_key'));
    }

    private static function api(): string
    {
        return rtrim(preg_replace('#/api/?$#', '', (string) config('services.open_wa.base_url')), '/') . '/api/files';
    }

    /**
     * Host `$bytes` and return its public URL.
     * @param string $folder what it is for: voice-replies, invoices, exports …  (lowercase letters, digits, - _)
     */
    public static function put(string $bytes, int $companyId, string $folder, string $filename, ?string $mime = null): string
    {
        return self::store($bytes, $companyId, $folder, $filename, $mime)['url'];
    }

    /**
     * Like put(), but also says WHERE the file ended up, so a record (the Media Library) can find it again:
     * disk 'node' → path is the gateway storage key (files/{company}/{folder}/{name}); disk 'public' → path is on this server's disk (the fallback).
     * @return array{url:string, disk:string, path:string}
     */
    public static function store(string $bytes, int $companyId, string $folder, string $filename, ?string $mime = null): array
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION)) ?: 'bin';
        $mime ??= self::EXT_MIME[$ext] ?? null;

        if (self::enabled()) {
            try {
                $res = Http::withHeaders(['X-API-Key' => (string) config('services.open_wa.admin_key')])->timeout(60)->connectTimeout(5)
                    ->post(self::api(), array_filter(['companyId' => $companyId, 'folder' => $folder, 'filename' => $filename, 'mimetype' => $mime, 'data' => base64_encode($bytes)]));
                if ($res->successful() && filled($res->json('url'))) {
                    return ['url' => (string) $res->json('url'), 'disk' => 'node', 'path' => (string) ($res->json('key') ?: '')];
                }
                Log::warning("Node file host refused {$folder}/{$filename}: HTTP {$res->status()} — stored locally instead");
            } catch (\Throwable $e) {
                Log::warning("Node file host unreachable for {$folder}/{$filename}: {$e->getMessage()} — stored locally instead");
            }
        }

        $path = "{$folder}/{$companyId}/" . Str::uuid() . '.' . $ext;
        Storage::disk('public')->put($path, $bytes);
        return ['url' => Storage::disk('public')->url($path), 'disk' => 'public', 'path' => $path];
    }

    /** The bytes behind a hosted URL (for copying a file), or null when they cannot be fetched. */
    public static function read(string $url): ?string
    {
        try {
            $res = Http::timeout(60)->connectTimeout(5)->get($url);
            return $res->successful() ? $res->body() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Remove a file by the URL `put` returned — wherever it lives. Never throws; a file that is already gone is fine. */
    public static function delete(?string $url): void
    {
        if (!filled($url)) { return; }
        $path = (string) parse_url(trim($url), PHP_URL_PATH);

        if (preg_match('#/api/files/public/(\d{1,12})/([a-z0-9_-]{1,40})/([A-Za-z0-9-]{8,40}\.[a-z0-9]{1,8})$#', $path, $m)) {
            try {
                // the gateway reads the key from the query string
                Http::withHeaders(['X-API-Key' => (string) config('services.open_wa.admin_key')])->timeout(15)
                    ->delete(self::api() . '?key=' . rawurlencode("files/{$m[1]}/{$m[2]}/{$m[3]}"));
            } catch (\Throwable $e) {
                Log::warning("Could not delete hosted file {$path}: {$e->getMessage()}");
            }
            return;
        }

        if (preg_match('#^/storage/(.+)$#', $path, $m)) {   // written to this server's disk (before the move, or as the fallback)
            $relative = rawurldecode($m[1]);
            if (!str_contains($relative, '..') && !str_contains($relative, "\0")) {
                Storage::disk('public')->delete($relative);
            }
        }
    }
}
