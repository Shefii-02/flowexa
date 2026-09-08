<?php

namespace App\Modules\Instagram\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\InstagramAccount;
use App\Modules\Instagram\Jobs\ProcessInstagramEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * The Instagram webhook: `comments` (keyword bot) and `messages` (DM inbox + AI agent). The heavy
 * lifting runs in {@see ProcessInstagramEvent} so Meta always gets a fast 200.
 */
class InstagramWebhookController extends Controller
{
    public function verify(Request $request): mixed
    {
        $expected = config('services.instagram.webhook_verify_token');
        if ($request->query('hub_mode') === 'subscribe'
            && $expected
            && hash_equals((string) $expected, (string) $request->query('hub_verify_token'))) {
            return response($request->query('hub_challenge'), 200);
        }
        return response('Forbidden', 403);
    }

    public function handle(Request $request): mixed
    {
        if (!$this->signatureValid($request)) {
            Log::warning('InstagramWebhookController: bad signature');
            return response('invalid signature', 403);
        }

        foreach ($request->input('entry', []) as $entry) {
            $igUserId = $entry['id'] ?? null;
            $account = $igUserId
                ? InstagramAccount::where('ig_user_id', $igUserId)->where('is_active', true)->first()
                : null;
            if (!$account) {
                continue;
            }

            // Comments (+ other `changes` fields).
            foreach ($entry['changes'] ?? [] as $change) {
                $field = $change['field'] ?? null;
                $value = $change['value'] ?? [];

                if ($field === 'comments') {
                    ProcessInstagramEvent::dispatch('comment', $account->id, $value);
                } elseif ($field === 'messages') {
                    $msg = $this->normalizeMessage($value, $igUserId);
                    if ($msg) ProcessInstagramEvent::dispatch('message', $account->id, $msg);
                }
            }

            // Messenger-style `messaging` array (some app configs deliver DMs here).
            foreach ($entry['messaging'] ?? [] as $m) {
                $msg = $this->normalizeMessaging($m, $igUserId);
                if ($msg) ProcessInstagramEvent::dispatch('message', $account->id, $msg);
            }
        }

        return response('OK', 200);
    }

    /** `changes[field=messages].value` → normalized message payload, or null if it's an echo/own. */
    private function normalizeMessage(array $value, ?string $igUserId): ?array
    {
        $senderId = $value['sender']['id'] ?? ($value['from']['id'] ?? null);
        if (!$senderId || $senderId === $igUserId) {
            return null; // our own echo
        }
        return [
            'sender_id'   => $senderId,
            'username'    => $value['sender']['username'] ?? ($value['from']['username'] ?? null),
            'mid'         => $value['message']['mid'] ?? ($value['mid'] ?? null),
            'text'        => $value['message']['text'] ?? ($value['text'] ?? ''),
            'attachments' => $value['message']['attachments'] ?? [],
        ];
    }

    /** `entry[].messaging[]` (Messenger shape) → normalized message payload. */
    private function normalizeMessaging(array $m, ?string $igUserId): ?array
    {
        $senderId = $m['sender']['id'] ?? null;
        if (!$senderId || $senderId === $igUserId || !empty($m['message']['is_echo'])) {
            return null;
        }
        return [
            'sender_id'   => $senderId,
            'username'    => null,
            'mid'         => $m['message']['mid'] ?? null,
            'text'        => $m['message']['text'] ?? '',
            'attachments' => $m['message']['attachments'] ?? [],
        ];
    }

    private function signatureValid(Request $request): bool
    {
        $secret = config('services.instagram.app_secret');
        if (!$secret) {
            return true;
        }
        $header = $request->header('X-Hub-Signature-256', '');
        if (!str_starts_with($header, 'sha256=')) {
            return false;
        }
        return hash_equals('sha256=' . hash_hmac('sha256', $request->getContent(), $secret), $header);
    }
}
