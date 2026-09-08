<?php

namespace App\Modules\MetaAds\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin, resilient wrapper around the Meta Graph API.
 *
 * What it adds over a bare Http::get/post:
 *  - retries transient failures (HTTP 5xx and Meta's rate-limit error codes) with exponential backoff;
 *  - follows `paging.next` cursors so a caller gets the whole result set, not just the first page;
 *  - turns every failure into a typed {@see MetaApiException} carrying Meta's error code / subcode /
 *    `fbtrace_id`, so callers and logs can tell "bad token" from "rate limited" from "invalid field".
 */
class MetaGraphClient
{
    private string $baseUrl;

    /** Meta error codes that mean "back off and retry" rather than "this request is wrong". */
    private const RETRYABLE_CODES = [1, 2, 4, 17, 32, 341, 613];

    public function __construct(?string $version = null)
    {
        $version = $version ?: config('services.meta_ads.graph_version', 'v21.0');
        $this->baseUrl = rtrim("https://graph.facebook.com/{$version}", '/');
    }

    public function get(string $path, string $token, array $params = []): array
    {
        return $this->send('get', $path, $token, $params);
    }

    public function post(string $path, string $token, array $data = []): array
    {
        return $this->send('post', $path, $token, $data);
    }

    public function delete(string $path, string $token, array $params = []): array
    {
        return $this->send('delete', $path, $token, $params);
    }

    /**
     * GET every page of a list edge, following `paging.next`. `$max` caps the total rows pulled so a
     * runaway account can't spool an unbounded response into memory.
     */
    public function getAllPages(string $path, string $token, array $params = [], int $max = 5000): array
    {
        $rows = [];
        $params['limit'] = $params['limit'] ?? 200;
        $header = $this->authHeader($token);
        $next = null;

        do {
            // `next` is a fully-formed absolute URL (it also carries the token in its query string,
            // but we keep sending the header too so a header-only setup can't 401 mid-pagination).
            $page = $this->request('get', $next ?? $this->url($path), $next ? [] : $params, $header);

            foreach ($page['data'] ?? [] as $row) {
                $rows[] = $row;
                if (count($rows) >= $max) return $rows;
            }
            $next = $page['paging']['next'] ?? null;
        } while ($next);

        return $rows;
    }

    // ───────────────────────────────────────────────────────────────────────

    private function send(string $method, string $path, string $token, array $payload): array
    {
        return $this->request($method, $this->url($path), $payload, $this->authHeader($token));
    }

    private function url(string $path): string
    {
        return str_starts_with($path, 'http') ? $path : $this->baseUrl . '/' . ltrim($path, '/');
    }

    private function authHeader(string $token): array
    {
        return ['Authorization' => "Bearer {$token}"];
    }

    private function request(string $method, string $url, array $payload, array $headers, int $attempt = 1): array
    {
        /** @var Response $response */
        $response = Http::withHeaders($headers)
            ->timeout(30)
            ->{$method}($url, $payload);

        if ($response->successful()) {
            return $response->json() ?? [];
        }

        $error   = $response->json('error') ?? [];
        $code    = (int) ($error['code'] ?? 0);
        $subcode = $error['error_subcode'] ?? null;
        $message = $error['message'] ?? "Meta Graph API error (HTTP {$response->status()})";
        $trace   = $error['fbtrace_id'] ?? null;

        $retryable = $response->status() >= 500 || in_array($code, self::RETRYABLE_CODES, true);
        if ($retryable && $attempt < 3) {
            $delay = (2 ** $attempt) + (mt_rand(0, 1000) / 1000); // 2s, 4s (+ jitter)
            Log::warning('MetaGraphClient: retrying after transient error', [
                'attempt' => $attempt, 'code' => $code, 'status' => $response->status(), 'fbtrace_id' => $trace,
            ]);
            usleep((int) ($delay * 1_000_000));
            return $this->request($method, $url, $payload, $headers, $attempt + 1);
        }

        throw new MetaApiException($message, $code, $subcode, $trace, $response->status());
    }
}
