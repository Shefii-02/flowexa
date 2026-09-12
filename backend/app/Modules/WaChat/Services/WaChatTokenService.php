<?php

namespace App\Modules\WaChat\Services;

use App\Models\Company;
use App\Modules\WaChat\Models\WahaSession;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Owns the per-company API key for the open-wa node gateway
 * (`Company.wa_chat_token`, sent as the `X-API-Key` header).
 *
 * SaaS model: the node gateway has no tenant concept — it is a flat list of API
 * keys, each with an `allowedSessions` allowlist. Every Flowexa company gets its
 * own OPERATOR key scoped to exactly the sessions it created (rows in
 * `waha_sessions`). A company therefore only ever sees / acts on its own
 * sessions; session creation & deletion is brokered here with the gateway's
 * ADMIN key, which then rewrites the company key's allowlist.
 */
class WaChatTokenService
{
    /**
     * A scoped key with an EMPTY allowlist is treated as unrestricted by the
     * gateway (it would see every tenant's sessions). Until a company has a real
     * session, pin its key to this nil UUID so the allowlist is non-empty and
     * matches nothing.
     */
    private const NIL_SESSION = '00000000-0000-0000-0000-000000000000';

    private string $base;

    public function __construct()
    {
        $this->base = rtrim((string) config('services.open_wa.base_url'), '/');
    }

    public function adminKey(): string
    {
        $key = (string) config('services.open_wa.admin_key');
        if ($key === '') {
            throw new RuntimeException(
                'WA_CHAT_ADMIN_KEY is not set — cannot mint or scope per-company WA Chat keys.'
            );
        }
        return $key;
    }

    public function baseUrl(): string
    {
        return $this->base;
    }

    /** The session ids (gateway UUIDs, stored in waha_sessions.session_name) this company owns. */
    public function sessionScope(Company $company): array
    {
        $ids = WahaSession::where('company_id', $company->id)
            ->pluck('session_name')
            ->filter()
            ->values()
            ->all();

        return $ids ?: [self::NIL_SESSION];
    }

    /**
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
     * Mint a fresh OPERATOR key scoped to this company's current sessions and
     * persist it (raw token + gateway key id). Revokes the previous key.
     *
     * @return string the new raw token
     */
    public function provision(Company $company): string
    {
        $previousToken = (string) ($company->wa_chat_token ?? '');
        $previousKeyId = (string) ($company->wa_chat_key_id ?? '');

        $res = Http::withHeaders(['X-API-Key' => $this->adminKey()])
            ->timeout(15)->connectTimeout(5)
            ->post("{$this->base}/auth/api-keys", [
                'name'            => "flowexa:{$company->slug} #{$company->id}",
                'role'            => 'operator',
                'allowedSessions' => $this->sessionScope($company),
            ]);

        if (! $res->successful()) {
            throw new RuntimeException(
                "Gateway refused to create a key (HTTP {$res->status()}): " . $res->body()
            );
        }

        $raw   = (string) ($res->json('apiKey') ?? '');
        $keyId = (string) ($res->json('id') ?? '');
        if ($raw === '' || $keyId === '') {
            throw new RuntimeException('Gateway created a key but returned no raw value / id.');
        }

        $company->forceFill([
            'wa_chat_token'            => $raw,
            'wa_chat_key_id'           => $keyId,
            'wa_chat_token_expires_at' => null,
            'wa_auth_enabled'          => true,
        ])->save();

        // Only ever revoke by a known, trusted key id. The old prefix-guess fallback here —
        // revokeByPrefix($previousToken) whenever wa_chat_key_id was empty — searched the
        // ENTIRE gateway key list for any key whose first 12 characters matched the company's
        // stale token and revoked whatever it found, with no check that the match actually
        // belonged to this company. In production this revoked a DIFFERENT, unrelated
        // company's valid key (a prefix collision) the moment a company with no recorded
        // key id was (re)provisioned. A company with no known previous key id simply gets a
        // fresh key here and any orphaned gateway-side key from its stale token is left alone
        // — harmless clutter is a vastly safer failure mode than revoking a stranger's key.
        if ($previousKeyId !== '' && $previousKeyId !== $keyId) {
            $this->revokeById($previousKeyId);
        }

        return $raw;
    }

    /**
     * Re-push this company's session allowlist to its gateway key. Call after
     * every session create / delete. No-op if the company was never provisioned.
     */
    public function syncSessions(Company $company): void
    {
        $keyId = (string) ($company->wa_chat_key_id ?? '');
        if ($keyId === '') {
            return;
        }

        try {
            Http::withHeaders(['X-API-Key' => $this->adminKey()])
                ->timeout(12)
                ->put("{$this->base}/auth/api-keys/{$keyId}", [
                    'allowedSessions' => $this->sessionScope($company),
                ]);
        } catch (\Throwable) {
            // Best-effort: the DB row (waha_sessions) is authoritative; a missed
            // sync is repaired by `wa-chat:token {company} --sync` or the next change.
        }
    }

    // ── Gateway's own error/audit trail (platform-wide, not company-scoped) ─────────

    /**
     * Live proxy to backend-node's own error trail (severity=error rows in its
     * audit_logs table) — read on demand, nothing is mirrored into Flowexa's DB.
     *
     * @return array{data: array, total: int}
     */
    public function gatewayErrors(int $limit = 40, int $offset = 0): array
    {
        $res = Http::withHeaders(['X-API-Key' => $this->adminKey()])
            ->timeout(10)->connectTimeout(4)
            ->get("{$this->base}/audit", ['severity' => 'error', 'limit' => $limit, 'offset' => $offset]);

        if (! $res->successful()) {
            throw new RuntimeException("Gateway returned HTTP {$res->status()} for /audit.");
        }

        return ['data' => $res->json('data') ?? [], 'total' => (int) ($res->json('total') ?? 0)];
    }

    /**
     * Live proxy to backend-node's full audit trail (every action, any severity) —
     * the "activity" counterpart to gatewayErrors()'s severity=error-only view.
     *
     * @return array{data: array, total: int}
     */
    public function gatewayActivity(int $limit = 40, int $offset = 0): array
    {
        $res = Http::withHeaders(['X-API-Key' => $this->adminKey()])
            ->timeout(10)->connectTimeout(4)
            ->get("{$this->base}/audit", ['limit' => $limit, 'offset' => $offset]);

        if (! $res->successful()) {
            throw new RuntimeException("Gateway returned HTTP {$res->status()} for /audit.");
        }

        return ['data' => $res->json('data') ?? [], 'total' => (int) ($res->json('total') ?? 0)];
    }

    /** Deletes every row (or only rows older than $olderThanDays) from the gateway's audit trail. */
    public function clearGatewayErrors(?int $olderThanDays = null): int
    {
        $url = "{$this->base}/audit" . ($olderThanDays !== null ? '?days=' . $olderThanDays : '');

        $res = Http::withHeaders(['X-API-Key' => $this->adminKey()])
            ->timeout(10)
            ->delete($url);

        if (! $res->successful()) {
            throw new RuntimeException("Gateway returned HTTP {$res->status()} clearing /audit.");
        }

        return (int) ($res->json('deleted') ?? 0);
    }

    public function revokeById(string $keyId): void
    {
        try {
            // Never revoke the gateway's own admin key — some legacy rows had it
            // copied straight into wa_chat_token.
            $adminId = $this->adminKeyId();
            if ($adminId !== null && $adminId === $keyId) {
                return;
            }

            Http::withHeaders(['X-API-Key' => $this->adminKey()])
                ->timeout(10)
                ->delete("{$this->base}/auth/api-keys/{$keyId}");
        } catch (\Throwable) {
            // orphaned gateway keys are harmless
        }
    }

    /**
     * UNSAFE — kept only as a manually-invoked last resort, never call this automatically.
     * A 12-character prefix match against the WHOLE gateway key list is not proof of
     * ownership: two different companies' keys can share the same prefix (this has
     * happened in production — see the comment in provision()), and this method will
     * revoke whichever key it finds first with no way to verify it's the right one.
     * Prefer revokeById() with a real, known key id every time.
     */
    public function revokeByPrefix(string $rawToken): void
    {
        if ($rawToken === $this->adminKey()) {
            return; // legacy: wa_chat_token == the admin key itself
        }

        try {
            $prefix = substr($rawToken, 0, 12);
            $match  = collect($this->listKeys())
                ->first(fn ($k) => ($k['keyPrefix'] ?? null) === $prefix);

            if ($match && isset($match['id'])) {
                $this->revokeById((string) $match['id']);
            }
        } catch (\Throwable) {
            // best-effort
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function listKeys(): array
    {
        $res = Http::withHeaders(['X-API-Key' => $this->adminKey()])
            ->timeout(10)->get("{$this->base}/auth/api-keys");

        return $res->successful() ? ($res->json('data') ?? $res->json() ?? []) : [];
    }

    private function adminKeyId(): ?string
    {
        $prefix = substr($this->adminKey(), 0, 12);
        $match  = collect($this->listKeys())->first(fn ($k) => ($k['keyPrefix'] ?? null) === $prefix);

        return $match['id'] ?? null;
    }
}
