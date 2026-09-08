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
        string $sessionId, string $apiKey, string $chatId, string $text,
        ?array $mentions = null, ?string $quotedMessageId = null,
    ): Response {
        return $this->post($sessionId, $apiKey, 'send-text', array_filter([
            'chatId'          => $chatId,
            'text'            => $text,
            'mentions'        => $mentions,
            'quotedMessageId' => $quotedMessageId,
        ], fn($v) => $v !== null));
    }

    // $mediaType: image | video | audio | document. $payload takes base64 XOR url, plus optional
    // mimetype, filename, caption, quotedMessageId — same shape as SendMediaPayload on the frontend.
    public function sendMedia(string $sessionId, string $apiKey, string $chatId, string $mediaType, array $payload): Response
    {
        return $this->post($sessionId, $apiKey, "send-{$mediaType}", array_merge(['chatId' => $chatId], $payload), 60);
    }

    public function sendLocation(
        string $sessionId, string $apiKey, string $chatId,
        float $latitude, float $longitude, ?string $description = null, ?string $address = null,
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
        string $sessionId, string $apiKey, string $chatId,
        string $name, array $options, bool $allowMultipleAnswers = false,
    ): Response {
        return $this->post($sessionId, $apiKey, 'send-poll', compact('chatId', 'name', 'options', 'allowMultipleAnswers'));
    }
}
