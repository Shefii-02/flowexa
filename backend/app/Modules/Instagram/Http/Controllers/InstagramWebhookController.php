<?php

namespace App\Modules\Instagram\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\InstagramAccount;
use App\Modules\Instagram\Jobs\ProcessInstagramEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * The Instagram webhook: `comments` (keyword bot + flows), `messages` (DM inbox + AI agent + echoes) and
 * `messaging_postbacks` (flow button taps). The heavy
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

            // This is the ONE webhook URL every company's Instagram accounts push through (Meta's
            // callback is multi-tenant, differentiated only by ig_user_id → company_id) — log the
            // company identification on every hit so a live event can be traced to its tenant.
            if ($account) {
                Log::info("Instagram webhook: ig_user_id={$igUserId} company_id={$account->company_id}");
            } else {
                Log::warning("Instagram webhook: ig_user_id={$igUserId} — no matching (active) company");
            }

            if (!$account) {
                continue;
            }

            // Comments (+ other `changes` fields).
            foreach ($entry['changes'] ?? [] as $change) {
                $field = $change['field'] ?? null;
                $value = $change['value'] ?? [];

                if ($field === 'comments') {
                    ProcessInstagramEvent::dispatch('comment', $account->id, $value);
                } elseif ($field === 'live_comments') {
                    ProcessInstagramEvent::dispatch('comment', $account->id, $value + ['_live' => true]);
                } elseif ($field === 'messages' || $field === 'messaging_postbacks') {
                    $this->dispatchMessaging($account->id, $value, $igUserId);
                }
            }

            // Messenger-style `messaging` array (the usual shape for DMs, postbacks and echoes).
            foreach ($entry['messaging'] ?? [] as $m) {
                $this->dispatchMessaging($account->id, $m, $igUserId);
            }
        }

        return response('OK', 200);
    }

    /**
     * One messaging event → the right job: a button tap (`postback` / quick-reply payload), an echo of
     * a message the business sent (e.g. an agent typing in the Instagram app), or a customer DM.
     */
    private function dispatchMessaging(int $accountId, array $m, ?string $igUserId): void
    {
        $senderId    = $m['sender']['id'] ?? ($m['from']['id'] ?? null);
        $recipientId = $m['recipient']['id'] ?? ($m['to']['id'] ?? null);
        $message     = $m['message'] ?? [];

        // Echo: the business is the sender; the customer is the recipient.
        if (!empty($message['is_echo']) || ($senderId && $senderId === $igUserId)) {
            if ($recipientId && $recipientId !== $igUserId) {
                // Delayed so our own API sends (stored with the same mid) win the race and aren't duplicated.
                ProcessInstagramEvent::dispatch('echo', $accountId, [
                    'participant_id' => $recipientId,
                    'mid'            => $message['mid'] ?? ($m['mid'] ?? null),
                    'text'           => $message['text'] ?? ($m['text'] ?? ''),
                    'attachments'    => $message['attachments'] ?? [],
                ])->delay(now()->addSeconds(6));
            }
            return;
        }
        if (!$senderId) {
            return;
        }

        $postbackPayload = $m['postback']['payload'] ?? ($message['quick_reply']['payload'] ?? null);
        if ($postbackPayload) {
            ProcessInstagramEvent::dispatch('postback', $accountId, [
                'sender_id' => $senderId,
                'username'  => $m['sender']['username'] ?? null,
                'mid'       => $m['postback']['mid'] ?? ($message['mid'] ?? null),
                'payload'   => $postbackPayload,
                'title'     => $m['postback']['title'] ?? ($message['text'] ?? ''),
            ]);
            return;
        }

        // ig.me/?ref= links and ads: treat the referral ref as the typed keyword.
        $referral = $m['referral']['ref'] ?? ($m['postback']['referral']['ref'] ?? null);
        if ($referral && !$message) {
            $message = ['text' => (string) $referral, 'mid' => $m['referral']['mid'] ?? null];
        }

        if ($message || isset($m['text'])) {
            ProcessInstagramEvent::dispatch('message', $accountId, [
                'story_reply' => !empty($message['reply_to']['story']),
                'sender_id'   => $senderId,
                'username'    => $m['sender']['username'] ?? ($m['from']['username'] ?? null),
                'mid'         => $message['mid'] ?? ($m['mid'] ?? null),
                'text'        => $message['text'] ?? ($m['text'] ?? ''),
                'attachments' => $message['attachments'] ?? [],
            ]);
        }
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
