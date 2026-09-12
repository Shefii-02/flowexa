<?php

namespace App\Http\Middleware;

use App\Models\ApiRequestLog;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Records one row per API request into api_request_logs — the raw material
 * behind the superadmin "API & Activity" page: request volume, latency and
 * error-rate stats site-wide and per company, plus a human activity feed
 * (the same rows, filtered to mutating methods).
 *
 * Terminable so the DB insert happens after the response is already on the
 * wire and never adds to the caller's perceived latency. Any failure here is
 * swallowed (via report()) rather than allowed to break the real request.
 */
class LogApiRequest
{
    /** Keys redacted wherever they appear in a logged request payload. */
    private const REDACT_KEYS = [
        'password', 'password_confirmation', 'owner_password', 'new_password',
        'token', 'api_key', 'api_token', 'secret', 'private_token', 'access_token',
        'authorization', 'wa_access_token', 'wa_chat_token', 'client_secret', 'app_secret',
    ];

    /** Path prefixes (relative to the app, no leading slash) never worth a row. */
    private const SKIP_PATH_PREFIXES = [
        'api/v1/superadmin/system-log',
        'api/v1/superadmin/api-logs',
        'api/v1/superadmin/errors',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $start = microtime(true);
        $response = $next($request);

        // Resolve the acting user now, while auth state from inner middleware
        // is still live — cheaper and more reliable than re-resolving in terminate().
        $request->attributes->set('__log_start', $start);
        $request->attributes->set('__log_user', $this->safeUser());

        return $response;
    }

    public function terminate(Request $request, Response $response): void
    {
        if ($request->method() === 'OPTIONS') {
            return;
        }

        $path = ltrim($request->path(), '/');
        foreach (self::SKIP_PATH_PREFIXES as $skip) {
            if (str_starts_with($path, $skip)) {
                return;
            }
        }

        try {
            /** @var User|null $user */
            $user  = $request->attributes->get('__log_user');
            $start = $request->attributes->get('__log_start', microtime(true));
            $status = $response->getStatusCode();

            ApiRequestLog::create([
                'company_id'       => $user?->company_id,
                'user_id'          => $user?->id,
                'actor_role'       => $user?->role?->name,
                'method'           => $request->method(),
                'path'             => '/' . $path,
                'route_name'       => optional($request->route())->getName(),
                'status_code'      => $status,
                'duration_ms'      => (int) round((microtime(true) - $start) * 1000),
                'ip'               => (string) substr((string) $request->ip(), 0, 45),
                'user_agent'       => (string) substr((string) $request->userAgent(), 0, 255),
                'request_summary'  => $this->summarize($this->redact($request->except(self::REDACT_KEYS))),
                'response_summary' => $status >= 400 ? $this->responseSummary($response) : null,
                'is_error'         => $status >= 400,
                'created_at'       => now(),
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function safeUser(): ?User
    {
        try {
            return auth('api')->user();
        } catch (Throwable) {
            return null;
        }
    }

    /** Recursively blank out any redacted key, in case it's nested. */
    private function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->redact($value);
            } elseif (in_array(strtolower((string) $key), self::REDACT_KEYS, true)) {
                $data[$key] = '[redacted]';
            }
        }
        return $data;
    }

    private function summarize(array $data): ?string
    {
        if (empty($data)) {
            return null;
        }
        $json = json_encode($data, JSON_UNESCAPED_SLASHES);
        return $json === false ? null : mb_substr($json, 0, 2000);
    }

    private function responseSummary(Response $response): ?string
    {
        $content = $response->getContent();
        if (!is_string($content) || $content === '') {
            return null;
        }
        return mb_substr($content, 0, 2000);
    }
}
