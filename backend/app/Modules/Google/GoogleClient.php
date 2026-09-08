<?php

namespace App\Modules\Google;

use App\Models\GoogleIntegration;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Google OAuth + Sheets v4 + Drive v3, over plain HTTP (no SDK dependency). Per-company: each
 * company connects its own Google account, and its leads land in a Sheet in ITS Drive.
 *
 * Scopes: drive.file (only files this app creates) + spreadsheets.
 */
class GoogleClient
{
    public const SCOPES = [
        'https://www.googleapis.com/auth/drive.file',
        'https://www.googleapis.com/auth/spreadsheets',
        'https://www.googleapis.com/auth/userinfo.email',
        'openid',
    ];

    public function configured(): bool
    {
        return filled(config('services.google.client_id')) && filled(config('services.google.client_secret'));
    }

    // ── OAuth ────────────────────────────────────────────────────────────

    public function authUrl(string $state): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'client_id'     => config('services.google.client_id'),
            'redirect_uri'  => config('services.google.redirect_uri'),
            'response_type' => 'code',
            'scope'         => implode(' ', self::SCOPES),
            'access_type'   => 'offline',
            'prompt'        => 'consent',
            'state'         => $state,
        ]);
    }

    /** @return array{access_token:string, refresh_token:?string, expires_in:int, email:?string} */
    public function exchangeCode(string $code): array
    {
        $res = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'code'          => $code,
            'client_id'     => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'redirect_uri'  => config('services.google.redirect_uri'),
            'grant_type'    => 'authorization_code',
        ]);
        if ($res->failed()) {
            throw new RuntimeException('Google token exchange failed: ' . ($res->json('error_description') ?? $res->body()));
        }
        $data = $res->json();
        return [
            'access_token'  => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? null,
            'expires_in'    => (int) ($data['expires_in'] ?? 3600),
            'email'         => $this->email($data['access_token']),
        ];
    }

    /** A valid access token for the integration, refreshing (and persisting) if it has expired. */
    public function token(GoogleIntegration $i): string
    {
        if (!$i->tokenExpired()) {
            return $i->access_token;
        }
        if (!$i->refresh_token) {
            throw new RuntimeException('Google connection expired and cannot be refreshed — reconnect the account.');
        }

        $res = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id'     => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'refresh_token' => $i->refresh_token,
            'grant_type'    => 'refresh_token',
        ]);
        if ($res->failed()) {
            $i->update(['is_active' => false, 'last_error' => 'token refresh failed']);
            throw new RuntimeException('Google token refresh failed — reconnect the account.');
        }
        $data = $res->json();
        $i->update([
            'access_token'     => $data['access_token'],
            'token_expires_at' => now()->addSeconds((int) ($data['expires_in'] ?? 3600)),
            'is_active'        => true,
            'last_error'       => null,
        ]);
        return $data['access_token'];
    }

    private function email(string $accessToken): ?string
    {
        $res = Http::withToken($accessToken)->get('https://www.googleapis.com/oauth2/v3/userinfo');
        return $res->ok() ? $res->json('email') : null;
    }

    // ── Drive ────────────────────────────────────────────────────────────

    /** @return array{id:string, url:string} */
    public function createFolder(GoogleIntegration $i, string $name): array
    {
        $res = Http::withToken($this->token($i))->post('https://www.googleapis.com/drive/v3/files', [
            'name'     => $name,
            'mimeType' => 'application/vnd.google-apps.folder',
        ]);
        $this->guard($res, 'create Drive folder');
        $id = $res->json('id');
        return ['id' => $id, 'url' => "https://drive.google.com/drive/folders/{$id}"];
    }

    private function moveToFolder(GoogleIntegration $i, string $fileId, string $folderId): void
    {
        Http::withToken($this->token($i))
            ->patch("https://www.googleapis.com/drive/v3/files/{$fileId}?addParents={$folderId}&removeParents=root", []);
    }

    /**
     * Upload a file into the integration's Drive folder and make it link-viewable.
     * @return array{id:string, name:string, mime:string, size:int, view_url:string, download_url:string, thumbnail:?string}
     */
    public function uploadFile(GoogleIntegration $i, string $contents, string $name, string $mime, ?string $folderId): array
    {
        $meta = ['name' => $name, 'mimeType' => $mime];
        if ($folderId) {
            $meta['parents'] = [$folderId];
        }

        $boundary = 'wgt' . bin2hex(random_bytes(8));
        $body = "--{$boundary}\r\n"
              . "Content-Type: application/json; charset=UTF-8\r\n\r\n"
              . json_encode($meta) . "\r\n"
              . "--{$boundary}\r\n"
              . "Content-Type: {$mime}\r\n\r\n"
              . $contents . "\r\n"
              . "--{$boundary}--";

        $res = Http::withToken($this->token($i))
            ->withBody($body, "multipart/related; boundary={$boundary}")
            ->post('https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&fields=id,name,mimeType,size,thumbnailLink,webViewLink');
        $this->guard($res, 'upload file to Drive');
        $id = $res->json('id');

        // Anyone-with-the-link can view — needed for the listing image to render on a public site.
        Http::withToken($this->token($i))->post("https://www.googleapis.com/drive/v3/files/{$id}/permissions", [
            'role' => 'reader', 'type' => 'anyone',
        ]);

        return [
            'id'           => $id,
            'name'         => $res->json('name') ?? $name,
            'mime'         => $res->json('mimeType') ?? $mime,
            'size'         => (int) ($res->json('size') ?? strlen($contents)),
            'view_url'     => $res->json('webViewLink') ?? "https://drive.google.com/file/d/{$id}/view",
            // A hotlink usable directly in an <img>/<video> src.
            'download_url' => "https://drive.google.com/uc?export=view&id={$id}",
            'thumbnail'    => $res->json('thumbnailLink'),
        ];
    }

    /** List files in the integration's Drive folder (most recent first). */
    public function listFiles(GoogleIntegration $i, ?string $folderId, int $limit = 100): array
    {
        $q = $folderId ? "'{$folderId}' in parents and trashed=false" : 'trashed=false';
        $res = Http::withToken($this->token($i))->get('https://www.googleapis.com/drive/v3/files', [
            'q'        => $q,
            'orderBy'  => 'createdTime desc',
            'pageSize' => min($limit, 200),
            'fields'   => 'files(id,name,mimeType,size,thumbnailLink,webViewLink,createdTime)',
        ]);
        $this->guard($res, 'list Drive files');

        return collect($res->json('files') ?? [])->map(fn ($f) => [
            'id'           => $f['id'],
            'name'         => $f['name'] ?? null,
            'mime'         => $f['mimeType'] ?? null,
            'size'         => (int) ($f['size'] ?? 0),
            'view_url'     => $f['webViewLink'] ?? null,
            'download_url' => "https://drive.google.com/uc?export=view&id={$f['id']}",
            'thumbnail'    => $f['thumbnailLink'] ?? null,
            'created_at'   => $f['createdTime'] ?? null,
        ])->all();
    }

    // ── Sheets ───────────────────────────────────────────────────────────

    /** @return array{id:string, url:string} */
    public function createSpreadsheet(GoogleIntegration $i, string $title, ?string $folderId, array $header): array
    {
        $res = Http::withToken($this->token($i))->post('https://sheets.googleapis.com/v4/spreadsheets', [
            'properties' => ['title' => $title],
        ]);
        $this->guard($res, 'create spreadsheet');
        $id  = $res->json('spreadsheetId');
        $url = $res->json('spreadsheetUrl');

        if ($folderId) {
            $this->moveToFolder($i, $id, $folderId);
        }
        $this->appendRows($i, $id, [$header]);

        return ['id' => $id, 'url' => $url];
    }

    public function appendRows(GoogleIntegration $i, string $spreadsheetId, array $rows): void
    {
        if (empty($rows)) {
            return;
        }
        $res = Http::withToken($this->token($i))->post(
            "https://sheets.googleapis.com/v4/spreadsheets/{$spreadsheetId}/values/A1:append?valueInputOption=USER_ENTERED&insertDataOption=INSERT_ROWS",
            ['values' => $rows],
        );
        $this->guard($res, 'append rows');
    }

    private function guard(\Illuminate\Http\Client\Response $res, string $what): void
    {
        if ($res->failed()) {
            throw new RuntimeException("Google API — failed to {$what}: " . ($res->json('error.message') ?? $res->body()));
        }
    }
}
