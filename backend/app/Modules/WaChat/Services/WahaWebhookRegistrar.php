<?php

namespace App\Modules\WaChat\Services;

use App\Modules\WaChat\Models\WahaSession;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Subscribes the WA Chat (open-wa) gateway to push EVERY event back to Laravel's `v1/waha/webhook`.
 * Without that subscription the gateway never calls in, so automation rules, the AI agent and live chat updates never fire.
 */
class WahaWebhookRegistrar
{
    private const PATH = '/api/v1/waha/webhook';

    /**
     * The address the gateway must call. Public hosts are always https: a plain-http hook gets redirected by the web server
     * and the gateway refuses to follow redirects (SSRF guard), so every delivery would fail after its retries. This also
     * covers an APP_URL that was left as http://.
     */
    public static function targetUrl(): string
    {
        $url  = url(self::PATH);
        $host = (string) parse_url($url, PHP_URL_HOST);
        $local = in_array($host, ['localhost', '127.0.0.1', '::1'], true) || str_ends_with($host, '.test') || str_ends_with($host, '.local') || !str_contains($host, '.');
        return (!$local && str_starts_with($url, 'http://')) ? 'https://' . substr($url, 7) : $url;
    }

    private function base(): string
    {
        return rtrim(preg_replace('#/api/?$#', '', (string) config('services.open_wa.base_url')), '/');
    }

    private function headers(): array
    {
        return ['X-API-Key' => (string) config('services.open_wa.admin_key')];
    }

    /** Idempotent. A hook already pointing here is left alone; an older/narrower one is fixed in place (never duplicated). */
    public function ensure(WahaSession $session): bool
    {
        $target = self::targetUrl();
        $base   = "{$this->base()}/api/sessions/{$session->session_name}/webhooks";

        try {
            $existing = Http::withHeaders($this->headers())->get($base);

            if ($existing->successful()) {
                foreach ((array) $existing->json() as $hook) {
                    $url = (string) ($hook['url'] ?? '');
                    if ($url === $target) {
                        if (!in_array('*', (array) ($hook['events'] ?? []), true)) {
                            Http::withHeaders($this->headers())->put("{$base}/{$hook['id']}", ['events' => ['*']]);
                        }
                        return true;
                    }
                    // Same endpoint over the wrong scheme (http:// → redirected → refused): repoint it instead of adding a second hook.
                    if ($this->sameEndpoint($url, $target)) {
                        Http::withHeaders($this->headers())->put("{$base}/{$hook['id']}", ['url' => $target, 'events' => ['*']]);
                        Log::info('WA Chat webhook URL corrected', ['session' => $session->session_name, 'from' => $url, 'to' => $target]);
                        return true;
                    }
                }
            }

            Http::withHeaders($this->headers())->post($base, ['url' => $target, 'events' => ['*']]);
            return true;
        } catch (\Throwable $e) {
            Log::error('WA Chat webhook registration failed: ' . $e->getMessage());
            return false;
        }
    }

    private function sameEndpoint(string $url, string $target): bool
    {
        $strip = fn (string $u) => preg_replace('#^https?://#i', '', rtrim($u, '/'));
        return $url !== '' && $strip($url) === $strip($target);
    }
}
