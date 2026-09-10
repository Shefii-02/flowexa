<?php

namespace App\Modules\WaChat\Services;

use App\Models\Company;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Owns the per-company API key for the open-wa node gateway
 * (`Company.wa_chat_token`, sent as the `X-API-Key` header).
 *
 * The node gateway has no tenant model — it is a flat list of API keys. Each
 * Flowexa company gets its own key, minted here with the gateway's ADMIN key.
 */
class WaChatTokenService
{
    private string $base;

    public function __construct()
    {
        $this->base = rtrim((string) config('services.open_wa.base_url'), '/');
    }

    private function adminKey(): string
    {
        $key = (string) config('services.open_wa.admin_key');
        if ($key === '') {
            throw new RuntimeException(
                'WA_CHAT_ADMIN_KEY is not set — cannot mint per-company WA Chat keys.'
            );
        }
        return $key;
    }

    /**
     * Live status of a company's stored token, checked against the gateway.
     *
     * @return array{configured:bool, valid:bool, reason:string, gateway:string, checked_at:string}
     */
    public function status(Company $company): array
    {
        $token = (string) ($company->wa_chat_token ?? '');
        $base  = ['gateway' => $this->base, 'checked_at' => now()->toIso8601String()];

        if ($token === '') {
            return $base + ['configured' => false, 'valid' => false, 'reason' => 'No token stored for this company.'];
        }

        try {
            $res = Http::withHeaders(['X-API-Key' => $token])
                ->timeout(8)->connectTimeout(4)
                ->get("{$this->base}/sessions");
        } catch (\Throwable $e) {
            return $base + ['configured' => true, 'valid' => false, 'reason' => 'Gateway unreachable: ' . $e->getMessage()];
        }

        if ($res->successful()) {
            return $base + ['configured' => true, 'valid' => true, 'reason' => 'OK'];
        }

        if ($res->status() === 401) {
            $msg = (string) ($res->json('message') ?? 'Unauthorized');
            return $base + ['configured' => true, 'valid' => false, 'reason' => "Gateway rejected the token: {$msg}"];
        }

        return $base + ['configured' => true, 'valid' => false, 'reason' => "Gateway returned HTTP {$res->status()}."];
    }

    /**
     * Mint a fresh gateway key for the company and persist it. Best-effort revoke
     * of the previous key so orphans don't pile up on the gateway.
     *
     * @return string the new raw token (also stored on the company)
     */
    public function provision(Company $company): string
    {
        $previous = (string) ($company->wa_chat_token ?? '');

        $res = Http::withHeaders(['X-API-Key' => $this->adminKey()])
            ->timeout(15)->connectTimeout(5)
            ->post("{$this->base}/auth/api-keys", [
                'name' => "flowexa:{$company->slug} #{$company->id}",
                'role' => 'admin',
            ]);

        if (! $res->successful()) {
            throw new RuntimeException(
                "Gateway refused to create a key (HTTP {$res->status()}): " . $res->body()
            );
        }

        $raw = (string) ($res->json('apiKey') ?? '');
        if ($raw === '') {
            throw new RuntimeException('Gateway created a key but returned no raw value.');
        }

        $company->forceFill([
            'wa_chat_token'            => $raw,
            'wa_chat_token_expires_at' => null,
            'wa_auth_enabled'          => true,
        ])->save();

        if ($previous !== '' && $previous !== $raw) {
            $this->revoke($previous);
        }

        return $raw;
    }

    /** Best-effort: find the gateway key row for a raw token and revoke it. */
    public function revoke(string $rawToken): void
    {
        try {
            $list = Http::withHeaders(['X-API-Key' => $this->adminKey()])
                ->timeout(10)->get("{$this->base}/auth/api-keys");
            if (! $list->successful()) {
                return;
            }

            $prefix = substr($rawToken, 0, 12);
            $match  = collect($list->json('data') ?? $list->json() ?? [])
                ->first(fn ($k) => ($k['keyPrefix'] ?? null) === $prefix);

            if ($match && isset($match['id'])) {
                Http::withHeaders(['X-API-Key' => $this->adminKey()])
                    ->timeout(10)
                    ->delete("{$this->base}/auth/api-keys/{$match['id']}");
            }
        } catch (\Throwable) {
            // orphaned gateway keys are harmless; never fail a re-provision on this
        }
    }
}
