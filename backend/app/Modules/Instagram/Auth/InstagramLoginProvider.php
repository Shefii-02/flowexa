<?php

namespace App\Modules\Instagram\Auth;

use App\Models\InstagramAccount;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class InstagramLoginProvider implements InstagramAuthProvider
{
    private function cfg(string $key): mixed { return config("services.instagram.{$key}"); }

    private function graph(string $path): string
    {
        return "https://graph.instagram.com/" . ltrim($path, '/');
    }

    public function authorizeUrl(string $state): string
    {
        return 'https://www.instagram.com/oauth/authorize?' . http_build_query([
            'client_id'     => $this->cfg('app_id'),
            'redirect_uri'  => $this->cfg('redirect_uri'),
            'response_type' => 'code',
            'scope'         => $this->cfg('scopes'),
            'state'         => $state,
        ]);
    }

    public function exchangeCode(string $code): array
    {
        $code = explode('#', $code)[0]; // Instagram appends "#_" to the code

        $short = Http::asForm()->timeout(30)->withOptions(['allow_redirects' => ['strict' => true, 'max' => 3]])->post('https://api.instagram.com/oauth/access_token', [
            'client_id'     => $this->cfg('app_id'),
            'client_secret' => $this->cfg('app_secret'),
            'grant_type'    => 'authorization_code',
            'redirect_uri'  => $this->cfg('redirect_uri'),
            'code'          => $code,
        ]);
        if (!$short->successful()) {
            throw new RuntimeException($this->fail('code exchange', 'https://api.instagram.com/oauth/access_token', 'POST', $short, 'Instagram rejected the authorization code'));
        }
        // New responses wrap the token in `data[0]`; older ones are flat.
        $row = $short->json('data.0') ?? $short->json();
        if (empty($row['access_token'])) {
            throw new RuntimeException('Instagram did not return an access token.');
        }
        $scopes = $row['permissions'] ?? [];
        if (is_string($scopes)) $scopes = array_filter(explode(',', $scopes));

        $long = Http::timeout(30)->get($this->graph('access_token'), [
            'grant_type'    => 'ig_exchange_token',
            'client_secret' => $this->cfg('app_secret'),
            'access_token'  => $row['access_token'],
        ]);
        if (!$long->successful()) {
            throw new RuntimeException($this->fail('long-lived token', $this->graph('access_token'), 'GET', $long, 'Could not get a long-lived token'));
        }
        $token   = $long->json('access_token');
        $expires = $long->json('expires_in') ? now()->addSeconds((int) $long->json('expires_in')) : null;

        $me = Http::timeout(30)->withToken($token)->get($this->graph($this->cfg('ig_graph_version') . '/me'), [
            'fields' => 'user_id,username,name,profile_picture_url,followers_count,account_type',
        ]);
        if (!$me->successful() || !$me->json('user_id')) {
            throw new RuntimeException($this->fail('profile read', $this->graph($this->cfg('ig_graph_version') . '/me'), 'GET', $me, 'Could not read the Instagram profile'));
        }

        return [
            // `user_id` is the id Meta puts in webhook `entry[].id`.
            'ig_user_id'          => (string) $me->json('user_id'),
            'username'            => $me->json('username'),
            'name'                => $me->json('name'),
            'profile_picture_url' => $me->json('profile_picture_url'),
            'followers_count'     => (int) $me->json('followers_count', 0),
            'access_token'        => $token,
            'expires_at'          => $expires,
            'scopes'              => array_values($scopes),
        ];
    }

    public function refresh(InstagramAccount $account): array
    {
        $r = Http::timeout(30)->get($this->graph('refresh_access_token'), [
            'grant_type'   => 'ig_refresh_token',
            'access_token' => $account->access_token,
        ]);
        if (!$r->successful() || !$r->json('access_token')) {
            throw new RuntimeException($this->err($r, 'Token refresh failed'));
        }
        return [$r->json('access_token'), now()->addSeconds((int) $r->json('expires_in', 5184000))];
    }

    public function subscribe(InstagramAccount $account): bool
    {
        return Http::timeout(30)->withToken($account->access_token)
            ->post($this->graph($this->cfg('ig_graph_version') . '/me/subscribed_apps'), [
                'subscribed_fields' => $this->cfg('subscribed_fields'),
            ])->successful();
    }

    public function unsubscribe(InstagramAccount $account): void
    {
        Http::timeout(30)->withToken($account->access_token)
            ->delete($this->graph($this->cfg('ig_graph_version') . '/me/subscribed_apps'));
    }

    /**
     * Log which step failed (endpoint path, status, Meta's error — never tokens, secrets or the code) and return
     * a message that names the step, so "Unsupported request" can be traced to the exact call.
     */
    private function fail(string $step, string $url, string $method, $response, string $fallback): string
    {
        $msg = $this->err($response, $fallback);
        \Illuminate\Support\Facades\Log::warning('Instagram OAuth step failed', [
            'step' => $step, 'method' => $method, 'path' => parse_url($url, PHP_URL_HOST) . parse_url($url, PHP_URL_PATH),
            'status' => $response->status(), 'error_type' => $response->json('error.type') ?? $response->json('error_type'),
            'error_code' => $response->json('error.code') ?? $response->json('code'), 'message' => $msg,
        ]);
        return "{$msg} (during {$step})";
    }

    /** Error text only — never the request (which carries tokens/secrets). */
    private function err($response, string $fallback): string
    {
        return $response->json('error_message')
            ?? $response->json('error.message')
            ?? "{$fallback} (HTTP {$response->status()})";
    }
}
