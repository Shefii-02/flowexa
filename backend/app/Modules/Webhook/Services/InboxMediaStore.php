<?php

namespace App\Modules\Webhook\Services;

use App\Models\Company;
use App\Modules\Webhook\DTOs\InboundMessageDTO;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Keeps the actual file behind an inbox message (photo, voice note, video, document, sticker)
 * so the inbox can render it the way WhatsApp does, instead of a "[Image]" placeholder.
 *
 * Inbound: Meta only hands us a media id — resolve it to a download URL and fetch it with the
 * company's token (Meta media URLs aren't public), then store it on the public disk under an
 * unguessable name. Outbound: when the payload carried a public `link`, that link is used as is.
 *
 * Returned shape (stored under wa_messages.content['media']):
 *   { url, mime, filename, caption, size, voice, duration? }
 */
class InboxMediaStore
{
    private const MAX_BYTES = 25 * 1024 * 1024;

    private const MEDIA_TYPES = ['image', 'video', 'audio', 'document', 'sticker'];

    public static function isMedia(string $type): bool
    {
        return in_array($type, self::MEDIA_TYPES, true);
    }

    public function storeInbound(Company $company, InboundMessageDTO $dto): ?array
    {
        if (!self::isMedia($dto->type)) {
            return null;
        }

        $node    = $dto->rawPayload[$dto->type] ?? [];
        $mediaId = $node['id'] ?? null;
        if (!$mediaId || !$company->decrypt_wa_access_token) {
            return null;
        }

        try {
            $token = $company->decrypt_wa_access_token;
            try { $token = decrypt($token); } catch (\Exception) { /* already plain */ }

            $meta     = Http::withToken($token)->timeout(15)->get("https://graph.facebook.com/v21.0/{$mediaId}");
            $mediaUrl = $meta->successful() ? $meta->json('url') : null;
            if (!$mediaUrl) {
                return null;
            }

            $declared = (int) ($meta->json('file_size') ?? 0);
            if ($declared > self::MAX_BYTES) {
                return null;
            }

            $file = Http::withToken($token)->timeout(60)->get($mediaUrl);
            if (!$file->successful() || strlen($file->body()) > self::MAX_BYTES) {
                return null;
            }

            $mime = $node['mime_type'] ?? ($file->header('Content-Type') ?: 'application/octet-stream');
            $mime = trim(explode(';', $mime)[0]);
            $name = $node['filename'] ?? null;
            $ext  = $name ? pathinfo($name, PATHINFO_EXTENSION) : '';
            $ext  = $ext ?: $this->extensionFor($mime);

            $path = sprintf('wa-inbox/%d/%s/%s.%s', $company->id, now()->format('Y/m'), Str::random(40), $ext);
            Storage::disk('public')->put($path, $file->body());

            return [
                'url'      => Storage::disk('public')->url($path),
                'mime'     => $mime,
                'filename' => $name,
                'caption'  => $node['caption'] ?? null,
                'size'     => strlen($file->body()),
                'voice'    => $dto->type === 'audio' && !empty($node['voice']),
            ];
        } catch (\Throwable $e) {
            Log::warning('[inbox] could not store inbound media: ' . $e->getMessage(), ['company' => $company->id]);
            return null;
        }
    }

    /** Outbound payloads that were sent by public link keep that link; ones sent by uploaded id have nothing to show. */
    public function fromOutboundPayload(array $payload): ?array
    {
        $type = $payload['type'] ?? '';
        if (!self::isMedia($type)) {
            return null;
        }

        $node = $payload[$type] ?? [];
        $link = $node['link'] ?? null;
        if (!$link) {
            return null;
        }

        return [
            'url'      => $link,
            'mime'     => $this->mimeFromUrl($link, $type),
            'filename' => $node['filename'] ?? basename(parse_url($link, PHP_URL_PATH) ?: ''),
            'caption'  => $node['caption'] ?? null,
            'size'     => null,
            'voice'    => false,
        ];
    }

    private function extensionFor(string $mime): string
    {
        return match (true) {
            str_contains($mime, 'jpeg')        => 'jpg',
            str_contains($mime, 'png')         => 'png',
            str_contains($mime, 'webp')        => 'webp',
            str_contains($mime, 'gif')         => 'gif',
            str_contains($mime, 'ogg')         => 'ogg',
            str_contains($mime, 'mpeg')        => str_starts_with($mime, 'video') ? 'mpg' : 'mp3',
            str_contains($mime, 'mp4')         => str_starts_with($mime, 'audio') ? 'm4a' : 'mp4',
            str_contains($mime, '3gpp')        => '3gp',
            str_contains($mime, 'pdf')         => 'pdf',
            str_contains($mime, 'wordprocessingml') => 'docx',
            str_contains($mime, 'spreadsheetml')    => 'xlsx',
            str_contains($mime, 'presentationml')   => 'pptx',
            str_contains($mime, 'plain')       => 'txt',
            default                            => 'bin',
        };
    }

    private function mimeFromUrl(string $url, string $type): string
    {
        $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));
        return match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png'         => 'image/png',
            'webp'        => 'image/webp',
            'gif'         => 'image/gif',
            'mp4'         => 'video/mp4',
            '3gp'         => 'video/3gpp',
            'mp3'         => 'audio/mpeg',
            'ogg'         => 'audio/ogg',
            'm4a'         => 'audio/mp4',
            'pdf'         => 'application/pdf',
            default       => match ($type) {
                'image', 'sticker' => 'image/jpeg',
                'video'            => 'video/mp4',
                'audio'            => 'audio/mpeg',
                default            => 'application/octet-stream',
            },
        };
    }
}
