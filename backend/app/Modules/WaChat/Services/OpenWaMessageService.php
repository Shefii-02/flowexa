<?php

namespace App\Modules\WaChat\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// Thin client for the wa-chat gateway's message-sending REST API (the engine is open-wa, not
// WAHA — see https://docs.open-wa.org/api-reference/). Every endpoint follows the same shape:
// POST {base}/sessions/{sessionId}/messages/send-{type}, authenticated per-company via
// Company.wa_chat_token as the X-API-Key header (not a single shared key). Request bodies here
// mirror frontend/src/pages/wa-chat/api/api.ts's messageApi exactly, since that is the
// confirmed-working caller of this same API.
class OpenWaMessageService
{
    private string $baseUrl;

    public function __construct(?string $baseUrl = null)
    {
        $this->baseUrl = rtrim($baseUrl ?? config('services.open_wa.base_url'), '/');
    }

    private function post(string $sessionId, string $apiKey, string $path, array $body, int $timeout = 30): Response
    {
        $url = "{$this->baseUrl}/sessions/{$sessionId}/messages/{$path}";

        // Every send event, one line each, so a live campaign's request/response pair is visible in
        // laravel.log without attaching a debugger — 'base64'/'data' bodies are redacted since a
        // media payload can be megabytes of noise (and a media data: URI is not useful in a log line).
        Log::info("OpenWaMessageService: POST {$path}", [
            'url'        => $url,
            'session_id' => $sessionId,
            'body'       => $this->redactBody($body),
        ]);

        $response = Http::withHeaders(['X-API-Key' => $apiKey])
            ->timeout($timeout)
            ->post($url, $body);

        Log::info("OpenWaMessageService: response {$path}", [
            'session_id' => $sessionId,
            'status'     => $response->status(),
            'successful' => $response->successful(),
            'body'       => $response->body(),
        ]);

        return $response;
    }

    private function redactBody(array $body): array
    {
        foreach (['base64', 'data'] as $key) {
            if (isset($body[$key]) && is_string($body[$key]) && strlen($body[$key]) > 200) {
                $body[$key] = '[REDACTED ' . strlen($body[$key]) . ' bytes]';
            }
        }
        return $body;
    }

    // $mentions: chat ids (e.g. a @lid or @c.us id) to @-mention in a group message. $quotedMessageId:
    // reply to an earlier message. Both optional and omitted from the body entirely when unset,
    // rather than sent as null/empty — matches how open-wa's own example requests only include the
    // fields that are actually in use.
    public function sendText(
        string $sessionId,
        string $apiKey,
        string $chatId,
        string $text,
        ?array $mentions = null,
        ?string $quotedMessageId = null,
        ?string $origin = null,
    ): Response {
        return $this->post($sessionId, $apiKey, 'send-text', array_filter([
            'chatId'          => $chatId,
            'text'            => $text,
            'mentions'        => $mentions,
            'quotedMessageId' => $quotedMessageId,
            'origin'          => $origin,
        ], fn($v) => $v !== null));
    }

    // $mediaType: image | video | audio | document. $payload takes base64 XOR url, plus optional
    // mimetype, filename, caption, quotedMessageId — same shape as SendMediaPayload on the frontend.
    public function sendMedia(string $sessionId, string $apiKey, string $chatId, string $mediaType, array $payload): Response
    {
        return $this->post($sessionId, $apiKey, "send-{$mediaType}", array_merge(['chatId' => $chatId], $payload), 90);
    }

    /**
     * Asks the gateway whether a number is on WhatsApp and what its canonical chat id is
     * (`…@c.us` or `…@lid`). Sending to a hand-built "<digits>@c.us" can fail inside WhatsApp Web
     * ("Data passed to getter must include an id property") when the account's real id differs or
     * doesn't exist, so callers resolve the id first.
     *
     * @return array{exists:bool, whatsappId:?string}|null null = the check itself was unavailable
     *         (caller should just fall back to the plain "<digits>@c.us" id).
     */
    public function checkNumber(string $sessionId, string $apiKey, string $number): ?array
    {
        try {
            $res = Http::withHeaders(['X-API-Key' => $apiKey])->timeout(10)
                ->get("{$this->baseUrl}/sessions/{$sessionId}/contacts/check/" . rawurlencode($number));
        } catch (\Throwable $e) {
            Log::warning("OpenWaMessageService: number check unavailable for {$number}: {$e->getMessage()}");
            return null;
        }
        if (!$res->successful()) {
            Log::warning("OpenWaMessageService: number check HTTP {$res->status()} for {$number}");
            return null;
        }

        $json = $res->json();
        $d    = is_array($json['data'] ?? null) ? $json['data'] : $json;
        if (!is_array($d) || !array_key_exists('exists', $d)) return null;

        Log::info("OpenWaMessageService: number check {$number}", ['exists' => (bool) $d['exists'], 'whatsappId' => $d['whatsappId'] ?? null]);
        return ['exists' => (bool) $d['exists'], 'whatsappId' => $d['whatsappId'] ?? null];
    }

    /**
     * Sends media by downloading the file HERE and handing the gateway its content (base64), instead
     * of giving the gateway a URL to fetch. When the gateway can't fetch a URL (private/relative
     * link, a web page instead of a file, a host that blocks it) WhatsApp Web fails with the cryptic
     * "Data passed to getter must include an id property" — fetching here makes that case work and
     * turns any real URL problem into a clear message.
     *
     * @param array $extra caption / filename / quotedMessageId (a 'url' key is ignored)
     * @throws \RuntimeException with a user-readable reason when the file can't be used
     */
    public function sendMediaFromUrl(string $sessionId, string $apiKey, string $chatId, string $mediaType, string $url, array $extra = []): Response
    {
        $file = $this->fetchMedia($url, $mediaType);
        unset($extra['url']);

        return $this->sendMedia($sessionId, $apiKey, $chatId, $mediaType, array_merge($extra, [
            'base64'   => $file['base64'],
            'mimetype' => $file['mimetype'],
            'filename' => $extra['filename'] ?? $file['filename'],
        ]));
    }

    private function fetchMedia(string $url, string $mediaType): array
    {
        $url = trim($url);
        if (str_starts_with($url, '/')) {
            $url = rtrim((string) config('app.url'), '/') . $url; // relative storage link -> absolute
        }
        if (!preg_match('#^https?://#i', $url)) {
            throw new \RuntimeException('Media link must be a full http(s) URL.');
        }

        try {
            $res = Http::timeout(40)->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; WaSender/1.0)'])->get($url);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Could not download the media file — the link is not reachable from the server (' . $e->getMessage() . ').');
        }
        if (!$res->successful()) {
            throw new \RuntimeException("Could not download the media file — the link returned HTTP {$res->status()}. It must be a public direct file link.");
        }

        $body = $res->body();
        if ($body === '') {
            throw new \RuntimeException('The media link returned an empty file.');
        }
        if (strlen($body) > 16 * 1024 * 1024) {
            throw new \RuntimeException('The media file is larger than 16 MB, which WhatsApp does not accept.');
        }

        $mime = strtolower(trim(explode(';', (string) $res->header('Content-Type'))[0]));
        if ($mime === '' || $mime === 'application/octet-stream') {
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($body) ?: 'application/octet-stream';
        }
        if (str_starts_with($mime, 'text/html')) {
            throw new \RuntimeException('The media link points to a web page, not a file. Use a direct link to the image/video/PDF itself.');
        }
        $prefix = ['image' => 'image/', 'video' => 'video/', 'audio' => 'audio/'][$mediaType] ?? null;
        if ($prefix && !str_starts_with($mime, $prefix)) {
            throw new \RuntimeException("The link is a {$mime} file, but this block is set to {$mediaType}. Change the block type or the link.");
        }

        $name = basename((string) parse_url($url, PHP_URL_PATH)) ?: 'file';

        return ['base64' => base64_encode($body), 'mimetype' => $mime, 'filename' => $name];
    }

    public function sendLocation(
        string $sessionId,
        string $apiKey,
        string $chatId,
        float $latitude,
        float $longitude,
        ?string $description = null,
        ?string $address = null,
    ): Response {
        return $this->post($sessionId, $apiKey, 'send-location', array_filter([
            'chatId'      => $chatId,
            'latitude'    => $latitude,
            'longitude'   => $longitude,
            'description' => $description,
            'address'     => $address,
        ], fn($v) => $v !== null));
    }

    public function sendContact(string $sessionId, string $apiKey, string $chatId, string $contactName, string $contactNumber): Response
    {
        return $this->post($sessionId, $apiKey, 'send-contact', compact('chatId', 'contactName', 'contactNumber'));
    }

    // Session status for a company's health check: 'ready' is the only status where sends actually
    // work end to end — everything else (qr_ready, starting, failed, disconnected, ...) means the
    // company cannot currently be reached over WhatsApp, which is the one bit the health check cares
    // about. Deliberately a plain GET (no request/response body logging like post() below) — this is
    // polled far more often than a send and carries no payload worth redacting.
    public function getSession(string $sessionId, string $apiKey): Response
    {
        return Http::withHeaders(['X-API-Key' => $apiKey])
            ->timeout(10)
            ->get("{$this->baseUrl}/sessions/{$sessionId}");
    }

    public function sendPoll(
        string $sessionId,
        string $apiKey,
        string $chatId,
        string $name,
        array $options,
        bool $allowMultipleAnswers = false,
    ): Response {
        return $this->post($sessionId, $apiKey, 'send-poll', compact('chatId', 'name', 'options', 'allowMultipleAnswers'));
    }
}
