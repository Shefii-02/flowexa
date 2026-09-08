<?php

namespace App\Support\Meta;

use App\Models\Company;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Uploads a sample media file to Meta via the resumable upload protocol
 * (create session → PUT bytes → receive handle "h").
 *
 * Copied from the live region of
 * App\Modules\Template\Http\Controllers\TemplateController::uploadMediaToMeta()
 * (~line 340), lifted into a static helper so the WaCloud module can reuse it
 * without importing that controller. Keep the two in sync.
 *
 * @return array{handle: string, path: string, url: string}|array{error: string}
 */
class MetaMediaUploader
{
    public static function upload(UploadedFile $file, Company $company, string $storageFolder): array
    {
        $token = MetaGraph::accessToken($company);

        if (!$token || !$company->meta_app_id) {
            Log::error('[meta-media-upload] missing WhatsApp credentials', [
                'company_id' => $company->id,
                'has_token'  => (bool) $token,
                'has_app_id' => (bool) $company->meta_app_id,
            ]);
            return ['error' => 'WhatsApp app Id and credentials not configured.'];
        }

        $path = $file->store($storageFolder, 'public');
        if (!$path) {
            Log::error('[meta-media-upload] local disk store returned no path', ['company_id' => $company->id]);
            return ['error' => 'Failed to save the file locally.'];
        }
        $publicUrl = Storage::disk('public')->url($path);

        try {
            $session = Http::withToken($token)
                ->timeout(15)
                ->post(MetaGraph::uploadsUrl($company->meta_app_id), [
                    'file_length' => $file->getSize(),
                    'file_type'   => $file->getMimeType(),
                ]);
        } catch (ConnectionException $e) {
            Storage::disk('public')->delete($path);
            Log::error('[meta-media-upload] connection timeout starting session', [
                'company_id' => $company->id,
                'error'      => $e->getMessage(),
            ]);
            return ['error' => 'Timed out starting upload session with Meta. Please try again.'];
        }

        if ($session->failed()) {
            Storage::disk('public')->delete($path);
            Log::error('[meta-media-upload] Meta rejected session start', [
                'company_id' => $company->id,
                'http_code'  => $session->status(),
                'body'       => $session->json(),
            ]);
            return ['error' => $session->json('error.message') ?? 'Failed to start upload session.'];
        }

        $uploadSessionId = $session->json('id'); // format: "upload:XYZ"

        try {
            $upload = Http::withHeaders([
                'Authorization' => 'OAuth ' . $token,
                'file_offset'   => '0',
            ])
                ->timeout(60)
                ->withBody(file_get_contents($file->getRealPath()), $file->getMimeType())
                ->post(MetaGraph::uploadSessionUrl($uploadSessionId));
        } catch (ConnectionException $e) {
            Storage::disk('public')->delete($path);
            Log::error('[meta-media-upload] connection timeout uploading bytes', [
                'company_id' => $company->id,
                'session_id' => $uploadSessionId,
                'error'      => $e->getMessage(),
            ]);
            return ['error' => 'Timed out uploading media to Meta. Please try again.'];
        }

        if ($upload->failed()) {
            Storage::disk('public')->delete($path);
            Log::error('[meta-media-upload] Meta rejected byte upload', [
                'company_id' => $company->id,
                'session_id' => $uploadSessionId,
                'http_code'  => $upload->status(),
                'body'       => $upload->json(),
            ]);
            return ['error' => $upload->json('error.message') ?? 'Failed to upload sample media.'];
        }

        $handle = $upload->json('h');
        if (!$handle) {
            Storage::disk('public')->delete($path);
            Log::error('[meta-media-upload] Meta returned success but no handle ("h")', [
                'company_id' => $company->id,
                'session_id' => $uploadSessionId,
                'body'       => $upload->json(),
            ]);
            return ['error' => 'Meta did not return a media handle. Please try again.'];
        }

        return ['handle' => $handle, 'path' => $path, 'url' => $publicUrl];
    }
}
