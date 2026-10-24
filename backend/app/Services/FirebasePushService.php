<?php

namespace App\Services;

use App\Models\PushToken;
use App\Models\PushNotification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FirebasePushService
{
    private string $fcmUrl = 'https://fcm.googleapis.com/v1/projects/{PROJECT_ID}/messages:send';

    // ── Send to specific user ─────────────────────────────────────────────
    public function sendToUser(int $userId, string $type, string $title, string $body, array $data = []): void
    {
        $tokens = PushToken::where('user_id', $userId)->where('is_active', true)->pluck('fcm_token');
        if ($tokens->isEmpty()) return;
        $this->sendToTokens($tokens->toArray(), $userId, null, $type, $title, $body, $data);
    }

    // ── Send to all company staff ─────────────────────────────────────────
    public function sendToCompany(int $companyId, string $type, string $title, string $body, array $data = []): void
    {
        $tokens = PushToken::where('company_id', $companyId)->where('is_active', true)->pluck('fcm_token');
        if ($tokens->isEmpty()) return;
        $this->sendToTokens($tokens->toArray(), null, $companyId, $type, $title, $body, $data);
    }

    // ── Lead assigned notification ─────────────────────────────────────────
    public function notifyLeadAssigned(int $assignedToUserId, int $leadId, string $contactName, string $category = ''): void
    {
        $this->sendToUser(
            $assignedToUserId,
            'lead_assigned',
            '🎯 New lead assigned',
            $category
                ? "Lead from {$contactName} ({$category}) has been assigned to you."
                : "A new lead from {$contactName} has been assigned to you.",
            ['lead_id' => $leadId, 'action' => 'open_lead']
        );
    }

    // ── Lead stage changed notification ────────────────────────────────────
    public function notifyLeadStageChange(int $companyId, int $leadId, string $contactName, string $newStage): void
    {
        $stageLabels = ['new' => 'New', 'contacted' => 'Contacted', 'follow_up' => 'Follow-up', 'enrolled' => 'Enrolled ✓', 'lost' => 'Lost'];
        $label       = $stageLabels[$newStage] ?? $newStage;

        $this->sendToCompany(
            $companyId,
            'lead_stage_change',
            "Lead updated → {$label}",
            "{$contactName}'s lead moved to {$label}.",
            ['lead_id' => $leadId, 'stage' => $newStage, 'action' => 'open_lead']
        );
    }

    // ── Campaign complete notification ─────────────────────────────────────
    public function notifyCampaignComplete(int $companyId, int $campaignId, string $campaignName, int $sent, float $deliveryRate): void
    {
        $this->sendToCompany(
            $companyId,
            'campaign_complete',
            '📢 Campaign completed',
            "{$campaignName} sent to {$sent} contacts ({$deliveryRate}% delivered).",
            ['campaign_id' => $campaignId, 'action' => 'open_campaign']
        );
    }

    // ── Low balance notification ───────────────────────────────────────────
    public function notifyLowBalance(int $companyId, int $balance): void
    {
        $this->sendToCompany(
            $companyId,
            'low_balance',
            '⚠️ Low wallet balance',
            "Only {$balance} messages remaining. Recharge to continue sending.",
            ['balance' => $balance, 'action' => 'open_wallet']
        );
    }

    // ── WhatsApp session went down ──────────────────────────────────────────
    public function notifySessionInactive(int $companyId, string $phone, ?string $reason = null): void
    {
        $this->sendToCompany(
            $companyId,
            'wa_session_inactive',
            '🔴 WhatsApp session disconnected',
            $reason
                ? "Your WhatsApp number ({$phone}) has disconnected: {$reason}"
                : "Your WhatsApp number ({$phone}) has disconnected. Messages will not send until it's reconnected.",
            ['phone' => $phone, 'action' => 'open_wa_session']
        );
    }

    // ── WhatsApp session came back up (after having been reported inactive) ─
    public function notifySessionRestored(int $companyId, string $phone): void
    {
        $this->sendToCompany(
            $companyId,
            'wa_session_restored',
            '🟢 WhatsApp session reconnected',
            "Your WhatsApp number ({$phone}) is reconnected and sending normally again.",
            ['phone' => $phone, 'action' => 'open_wa_session']
        );
    }

    // ── Inbound lead notification (flow triggered) ─────────────────────────
    public function notifyNewLead(int $companyId, int $leadId, string $contactName, string $category): void
    {
        $this->sendToCompany(
            $companyId,
            'new_lead',
            '🎯 New lead from WhatsApp',
            "{$contactName} is interested in {$category}.",
            ['lead_id' => $leadId, 'action' => 'open_lead']
        );
    }

    // ── Core send method ───────────────────────────────────────────────────
    private function sendToTokens(array $tokens, ?int $userId, ?int $companyId, string $type, string $title, string $body, array $data): void
    {
        $projectId    = config('services.firebase.project_id');
        $accessToken  = $this->getAccessToken();
        $url          = str_replace('{PROJECT_ID}', $projectId, $this->fcmUrl);
        $sentCount    = 0;
        $errors       = [];

        foreach (array_chunk($tokens, 100) as $chunk) {
            foreach ($chunk as $token) {
                try {
                    $response = Http::withToken($accessToken)
                        ->timeout(10)
                        ->post($url, [
                            'message' => [
                                'token'        => $token,
                                'notification' => ['title' => $title, 'body' => $body],
                                'data'         => array_merge($data, ['type' => $type]),
                                'android'      => ['priority' => 'high', 'notification' => ['sound' => 'default', 'click_action' => 'FLUTTER_NOTIFICATION_CLICK']],
                                'apns'         => ['payload' => ['aps' => ['sound' => 'default', 'badge' => 1]]],
                            ],
                        ]);

                    if ($response->successful()) {
                        $sentCount++;
                    } else {
                        $err = $response->json('error.message') ?? 'Unknown error';
                        $errors[] = $err;

                        // Deactivate invalid tokens
                        if (str_contains($err, 'NOT_FOUND') || str_contains($err, 'UNREGISTERED')) {
                            PushToken::where('fcm_token', $token)->update(['is_active' => false]);
                        }
                    }
                } catch (\Exception $e) {
                    Log::error('FCM send error: ' . $e->getMessage());
                    $errors[] = $e->getMessage();
                }
            }
        }

        // Log notification
        PushNotification::create([
            'company_id'  => $companyId ?? PushToken::where('user_id', $userId)->value('company_id'),
            'user_id'     => $userId,
            'type'        => $type,
            'title'       => $title,
            'body'        => $body,
            'data'        => $data,
            'status'      => $sentCount > 0 ? 'sent' : 'failed',
            'sent_count'  => $sentCount,
            'error'       => !empty($errors) ? implode('; ', array_slice($errors, 0, 3)) : null,
        ]);
    }

    // ── Settings → Push notifications: configuration check ────────────────
    /** Step-by-step server-side health check. Never throws; each step is {key,label,ok,detail}. */
    public function diagnose(): array
    {
        $steps = [];
        $add = function (string $key, string $label, bool $ok, string $detail = '') use (&$steps) {
            $steps[] = compact('key', 'label', 'ok', 'detail');
            return $ok;
        };

        $projectId = (string) config('services.firebase.project_id');
        $placeholder = $projectId === '' || str_contains($projectId, 'your-project');
        if (!$add('project_id', 'Firebase project ID set', !$placeholder, $placeholder ? 'Set FIREBASE_PROJECT_ID in the backend .env.' : $projectId)) return $steps;

        $path = (string) config('services.firebase.credentials_path');
        $exists = $path !== '' && is_file($path) && is_readable($path);
        if (!$add('credentials_file', 'Service-account key file found', $exists, $exists ? basename($path) : 'FIREBASE_CREDENTIALS_PATH does not point to a readable file.')) return $steps;

        $json = json_decode((string) file_get_contents($path), true);
        $valid = is_array($json) && !empty($json['client_email']) && !empty($json['private_key']);
        if (!$add('credentials_valid', 'Key file is a valid service account', $valid, $valid ? (string) $json['client_email'] : 'Not a service-account JSON (needs client_email and private_key).')) return $steps;

        $match = ($json['project_id'] ?? null) === $projectId;
        $add('project_match', 'Key belongs to this project', $match, $match ? '' : 'Key is for "' . ($json['project_id'] ?? '?') . '" but FIREBASE_PROJECT_ID is "' . $projectId . '".');

        try {
            cache()->forget('firebase_access_token');
            $this->getAccessToken();
            $add('google_auth', 'Google accepted the key (access token issued)', true);
        } catch (\Throwable $e) {
            $add('google_auth', 'Google accepted the key (access token issued)', false, 'Google rejected the credentials — check the key is active and the FCM API is enabled.');
        }
        return $steps;
    }

    /** Sends a real test push to every active device of the user; returns per-device results. */
    public function sendTest(int $userId): array
    {
        $tokens = PushToken::where('user_id', $userId)->where('is_active', true)->get(['id', 'fcm_token', 'device_type']);
        if ($tokens->isEmpty()) return [];

        $accessToken = $this->getAccessToken();
        $url = str_replace('{PROJECT_ID}', config('services.firebase.project_id'), $this->fcmUrl);
        $out = [];
        foreach ($tokens as $t) {
            try {
                $r = Http::withToken($accessToken)->timeout(10)->post($url, ['message' => [
                    'token'        => $t->fcm_token,
                    'notification' => ['title' => '✅ Push is working', 'body' => 'This is a test notification from Teamzo.'],
                    'data'         => ['type' => 'test'],
                ]]);
                $err = $r->successful() ? null : ($r->json('error.message') ?? 'Unknown error');
                if ($err && (str_contains($err, 'NOT_FOUND') || str_contains($err, 'UNREGISTERED'))) {
                    PushToken::where('id', $t->id)->update(['is_active' => false]);
                }
                $out[] = ['device_type' => $t->device_type, 'ok' => $r->successful(), 'error' => $err];
            } catch (\Throwable $e) {
                $out[] = ['device_type' => $t->device_type, 'ok' => false, 'error' => $e->getMessage()];
            }
        }
        return $out;
    }

    // ── Get Firebase access token (Service Account) ────────────────────────
    private function getAccessToken(): string
    {
        // Use cached token (valid 1hr)
        $cached = cache('firebase_access_token');
        if ($cached) return $cached;

        $serviceAccount = json_decode(file_get_contents(config('services.firebase.credentials_path')), true);
        $now            = time();
        $payload        = [
            'iss'   => $serviceAccount['client_email'],
            'sub'   => $serviceAccount['client_email'],
            'aud'   => 'https://oauth2.googleapis.com/token',
            'iat'   => $now,
            'exp'   => $now + 3600,
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        ];

        $b64       = fn (string $v) => rtrim(strtr(base64_encode($v), '+/', '-_'), '=');
        $header    = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims    = $b64(json_encode($payload));
        $signature = '';
        openssl_sign("{$header}.{$claims}", $signature, $serviceAccount['private_key'], OPENSSL_ALGO_SHA256);
        $jwt       = "{$header}.{$claims}." . $b64($signature);

        $response  = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ])->json();

        $token = $response['access_token'] ?? throw new \RuntimeException($response['error_description'] ?? 'Could not obtain a Google access token.');
        cache(['firebase_access_token' => $token], 3500); // cache for ~58 min
        return $token;
    }

    public function notifyCompany(int $companyId, array $data = []): void
    {
        $tokens = PushToken::where('company_id', $companyId)->where('is_active', true)->pluck('fcm_token');
        if ($tokens->isEmpty()) return;
        $this->sendToCompany(
            $companyId,
            $data['type'] ?? 'notification',
            $data['title'] ?? 'Notification',
            $data['body'] ?? '',
            $data['data'] ?? []
        );
    }
}



// Hook into existing events (add to relevant services):
// After lead assigned:      app(FirebasePushService::class)->notifyLeadAssigned(...)
// After campaign complete:  app(FirebasePushService::class)->notifyCampaignComplete(...)
// After wallet debit (low): app(FirebasePushService::class)->notifyLowBalance(...)
// After new lead from flow: app(FirebasePushService::class)->notifyNewLead(...)
