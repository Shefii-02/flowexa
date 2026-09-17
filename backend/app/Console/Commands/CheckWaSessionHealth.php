<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Modules\WaChat\Models\WahaSession;
use App\Modules\WaChat\Models\WahaWebhook;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Server-side counterpart to the frontend's own SessionStatusIndicator poll (GET
 * /waha/sessions/health, once a minute per open browser tab — cheap for one company, but that
 * poll only runs while someone has a tab open, and firing a webhook from inside a GET a browser
 * happens to make would be a strange place to hide a side effect). This command instead checks
 * every company's sessions on a schedule (see routes/console.php) and — on an actual
 * connected → disconnected transition, not just "still disconnected" — notifies that company's
 * own registered WahaWebhook subscribers (a config surface that already existed via
 * WahaWebhookConfigController but had no dispatcher actually calling it).
 */
class CheckWaSessionHealth extends Command
{
    protected $signature = 'wa-chat:check-health';

    protected $description = "Poll every company's WhatsApp sessions for live status and fire webhooks on a connected\u{2192}disconnected transition";

    private const CONNECTED_STATES = ['ready', 'connected', 'working', 'authenticated'];

    public function handle(): int
    {
        $base = rtrim((string) config('services.open_wa.base_url'), '/');
        $companies = Company::whereNotNull('wa_chat_token')->where('wa_chat_token', '!=', '')->get();

        $checked = 0;
        $transitioned = 0;

        foreach ($companies as $company) {
            $sessions = WahaSession::where('company_id', $company->id)->get();
            if ($sessions->isEmpty()) {
                continue;
            }

            try {
                $res = Http::withHeaders(['X-API-Key' => $company->wa_chat_token])
                    ->timeout(8)->connectTimeout(4)
                    ->get("{$base}/sessions");
            } catch (\Throwable) {
                // Gateway unreachable — a blip here must NOT be read as "every session just
                // disconnected"; skip this company this tick and let the next run re-check.
                continue;
            }
            if (!$res->successful()) {
                continue;
            }

            $rows = collect($res->json('data', $res->json() ?? []))
                ->filter(fn ($s) => is_array($s))
                ->keyBy(fn ($s) => $s['id'] ?? $s['name'] ?? null);

            foreach ($sessions as $session) {
                $checked++;
                $live = $rows->get($session->session_name);
                // A session missing from the gateway's own list (deleted there, or the row's
                // session_name never matched) is treated the same as disconnected, not skipped.
                $liveStatus = $live ? strtolower((string) ($live['status'] ?? 'unknown')) : 'disconnected';
                $wasConnected = in_array(strtolower((string) $session->status), self::CONNECTED_STATES, true);
                $isConnected = in_array($liveStatus, self::CONNECTED_STATES, true);

                if ($wasConnected && !$isConnected) {
                    $transitioned++;
                    $this->fireDisconnectWebhooks($company, $session);
                }

                $session->update([
                    'status' => $liveStatus,
                    'last_seen_at' => $isConnected ? now() : $session->last_seen_at,
                ]);
            }
        }

        $this->info("Checked {$checked} session(s) across {$companies->count()} company(ies); {$transitioned} newly disconnected.");
        return self::SUCCESS;
    }

    /** Notifies every active webhook this company registered for 'session.disconnected' — either
     *  scoped to this exact session or registered with no session filter (all sessions). */
    private function fireDisconnectWebhooks(Company $company, WahaSession $session): void
    {
        $webhooks = WahaWebhook::where('company_id', $company->id)
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('session_id')->orWhere('session_id', $session->id))
            ->get()
            ->filter(fn ($w) => empty($w->events) || in_array('session.disconnected', $w->events, true));

        if ($webhooks->isEmpty()) {
            return;
        }

        $payload = [
            'event' => 'session.disconnected',
            'session' => [
                'id' => $session->id,
                'name' => $session->display_name ?: $session->session_name,
                'phone' => $session->phone,
            ],
            'company_id' => $company->id,
            'timestamp' => now()->toIso8601String(),
        ];

        foreach ($webhooks as $webhook) {
            try {
                $req = Http::timeout(8);
                if (filled($webhook->secret)) {
                    $req = $req->withHeaders(['X-Webhook-Secret' => $webhook->secret]);
                }
                $res = $req->post($webhook->url, $payload);
                $webhook->update(['last_triggered_at' => now(), 'last_status_code' => $res->status()]);
            } catch (\Throwable) {
                // The row's own last_status_code/last_triggered_at is the company's visibility
                // into delivery failures (surfaced on the webhook config screen) — no need to
                // also fail this command run over one subscriber's unreachable endpoint.
                $webhook->update(['last_triggered_at' => now(), 'last_status_code' => 0]);
            }
        }
    }
}
