<?php

namespace App\Modules\MetaAds\Services;

use App\Models\MetaAdAccount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * "Continue with Facebook" for Meta ad accounts. Takes the user token a Facebook Login (or a pasted token) produced and works out everything
 * needed to connect: a long-lived version of the token, when it expires, which permissions it really has, and which ad accounts and Pages
 * it can use. Nothing is saved here — the controller keeps the token on the server and the browser only ever sees a short-lived handle.
 */
class MetaAdsConnectService
{
    /** What the Facebook Login asks for: manage + read ads, list business ad accounts, list Pages and read their lead forms. */
    public const SCOPES = ['ads_management', 'ads_read', 'business_management', 'pages_show_list', 'pages_read_engagement', 'leads_retrieval', 'pages_manage_ads'];
    /** Without these, connecting would "work" and then fail on the first real call — so the screen warns. */
    public const REQUIRED = ['ads_management', 'ads_read'];
    public const STATUS = [1 => 'active', 2 => 'disabled', 3 => 'unsettled', 7 => 'pending_risk_review', 9 => 'in_grace_period'];

    public function __construct(private readonly MetaGraphClient $graph) {}

    private function appCredentials(): array
    {
        return [(string) config('services.messenger.app_id'), (string) (config('services.meta_ads.app_secret') ?: config('services.messenger.app_secret'))];
    }

    private function graphUrl(string $path): string
    {
        return 'https://graph.facebook.com/' . config('services.meta_ads.graph_version', 'v21.0') . $path;
    }

    /** Swap a short-lived login token (about an hour) for a long-lived one (about 60 days). Falls back to the original when Meta will not (e.g. a system-user token). @return array{0:string,1:bool} */
    public function longLived(string $token): array
    {
        [$id, $secret] = $this->appCredentials();
        if ($id === '' || $secret === '') { return [$token, false]; }
        try {
            $res = Http::timeout(15)->get($this->graphUrl('/oauth/access_token'), ['grant_type' => 'fb_exchange_token', 'client_id' => $id, 'client_secret' => $secret, 'fb_exchange_token' => $token]);
            if ($res->successful() && filled($res->json('access_token'))) { return [(string) $res->json('access_token'), true]; }
        } catch (\Throwable) {
            // keep the token we were given
        }
        return [$token, false];
    }

    /** Expiry and granted permissions of a token. expires_at null + never = it does not expire; both null/false = unknown. @return array{expires_at:?Carbon,never:bool,scopes:string[]} */
    public function tokenInfo(string $token): array
    {
        $expires = null; $never = false; $scopes = [];
        [$id, $secret] = $this->appCredentials();
        if ($id !== '' && $secret !== '') {
            try {
                $d = Http::timeout(15)->get($this->graphUrl('/debug_token'), ['input_token' => $token, 'access_token' => "{$id}|{$secret}"])->json('data') ?? [];
                if (isset($d['expires_at'])) { $d['expires_at'] > 0 ? $expires = Carbon::createFromTimestamp((int) $d['expires_at']) : $never = true; }
                $scopes = array_values((array) ($d['scopes'] ?? []));
            } catch (\Throwable) {
                // expiry stays unknown
            }
        }
        if (!$scopes) {
            try {
                $scopes = collect($this->graph->get('me/permissions', $token)['data'] ?? [])->where('status', 'granted')->pluck('permission')->values()->all();
            } catch (\Throwable) {
                // permissions stay unknown
            }
        }
        return ['expires_at' => $expires, 'never' => $never, 'scopes' => $scopes];
    }

    /**
     * @return array{token:string, long_lived:bool, expires_at:?Carbon, never_expires:bool, scopes:string[], missing:string[], accounts:array<int,array<string,mixed>>, pages:array<int,array<string,mixed>>}
     * @throws MetaApiException when Meta rejects the token
     */
    public function discover(string $userToken, int $companyId): array
    {
        $this->graph->get('me', $userToken, ['fields' => 'id']);                // fails fast with Meta's own message when the token is bad
        [$token, $long] = $this->longLived($userToken);
        $info = $this->tokenInfo($token);

        $connected = MetaAdAccount::where('company_id', $companyId)->pluck('ad_account_id')->flip();
        $accounts = collect($this->graph->getAllPages('me/adaccounts', $token, ['fields' => 'account_id,name,currency,timezone_name,account_status,business{id,name}', 'limit' => 100], 500))
            ->map(fn ($a) => [
                'ad_account_id' => 'act_' . ltrim((string) ($a['account_id'] ?? $a['id'] ?? ''), 'act_'),
                'name' => $a['name'] ?? null, 'currency' => $a['currency'] ?? null, 'timezone' => $a['timezone_name'] ?? null,
                'status' => self::STATUS[(int) ($a['account_status'] ?? 1)] ?? 'active',
                'business_id' => $a['business']['id'] ?? null, 'business_name' => $a['business']['name'] ?? null,
                'connected' => isset($connected['act_' . ltrim((string) ($a['account_id'] ?? $a['id'] ?? ''), 'act_')]),
            ])->filter(fn ($a) => $a['ad_account_id'] !== 'act_')->values()->all();
        $pages = [];
        try {
            $pages = collect($this->graph->getAllPages('me/accounts', $token, ['fields' => 'id,name', 'limit' => 100], 500))->map(fn ($p) => ['id' => (string) $p['id'], 'name' => $p['name'] ?? null])->values()->all();
        } catch (\Throwable) {
            // no pages_show_list — ad accounts can still be connected, just without a Page
        }

        return ['token' => $token, 'long_lived' => $long, 'expires_at' => $info['expires_at'], 'never_expires' => $info['never'], 'scopes' => $info['scopes'],
                'missing' => $info['scopes'] ? array_values(array_diff(self::REQUIRED, $info['scopes'])) : [], 'accounts' => $accounts, 'pages' => $pages];
    }
}
