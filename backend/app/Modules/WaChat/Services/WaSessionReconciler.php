<?php

namespace App\Modules\WaChat\Services;

use App\Models\Company;
use App\Modules\WaChat\Models\WahaSession;
use App\Modules\WaChat\Models\WahaWebhook;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The chat gateway (backend-node / chatwa) owns WhatsApp sessions; Laravel keeps a local record of each. Deleting a session AT THE GATEWAY
 * (its own dashboard, API, or a delete that bypassed Laravel) used to leave that record behind for ever, because the gateway has no
 * "session deleted" webhook — it was just shown as "disconnected". This notices the gap and removes the local record.
 *
 * A record is removed only when the gateway ITSELF says the session does not exist (HTTP 404 on GET /sessions/{id}, asked with the admin
 * key). A list that merely lacks it, a timeout, 401/403 or 5xx never deletes anything, so a gateway outage cannot wipe the table.
 */
class WaSessionReconciler
{
    private static function base(): string
    {
        return rtrim((string) config('services.open_wa.base_url'), '/');
    }

    /** Does the gateway say, for certain, that this session no longer exists? */
    public static function confirmedGone(string $sessionName): bool
    {
        $key = (string) config('services.open_wa.admin_key');
        if ($key === '' || $sessionName === '') { return false; }
        try {
            return Http::withHeaders(['X-API-Key' => $key])->timeout(8)->connectTimeout(4)->get(self::base() . '/sessions/' . rawurlencode($sessionName))->status() === 404;
        } catch (\Throwable) {
            return false;
        }
    }

    /** Remove the local record and its webhook subscriptions (the FK would only null session_id and leave orphans). */
    public static function removeLocal(WahaSession $session): void
    {
        WahaWebhook::where('session_id', $session->id)->delete();
        $session->delete();
    }

    /**
     * Compare one company's local sessions with the gateway's list and drop the ones deleted there. Returns how many were removed.
     * Throttled per company so the Sessions page can call it on every load.
     */
    public static function reconcileCompany(Company $company, int $throttleSeconds = 60): int
    {
        if (!filled($company->wa_chat_token) || !Cache::add("wa-session-reconcile:{$company->id}", 1, $throttleSeconds)) { return 0; }
        $local = WahaSession::where('company_id', $company->id)->get();
        if ($local->isEmpty()) { return 0; }

        try {
            $res = Http::withHeaders(['X-API-Key' => $company->wa_chat_token])->timeout(8)->connectTimeout(4)->get(self::base() . '/sessions');
        } catch (\Throwable) {
            return 0;
        }
        if (!$res->successful()) { return 0; }
        $live = collect($res->json('data', $res->json() ?? []))->filter(fn ($s) => is_array($s))->map(fn ($s) => (string) ($s['id'] ?? $s['name'] ?? ''))->filter()->all();

        $removed = 0;
        foreach ($local as $s) {
            if (in_array($s->session_name, $live, true) || !self::confirmedGone($s->session_name)) { continue; }
            self::removeLocal($s);
            $removed++;
            Log::info("WA Chat session {$s->session_name} was deleted at the gateway — local record removed", ['company_id' => $company->id]);
        }
        if ($removed) {
            try { app(WaChatTokenService::class)->syncSessions($company); } catch (\Throwable) { /* allowlist is repaired on the next sync */ }
        }
        return $removed;
    }
}
