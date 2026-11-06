<?php

namespace App\Modules\Instagram\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\InstagramAccount;
use App\Modules\Instagram\Jobs\ProcessInstagramEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
                $this->store(null, $igUserId, 'other', null, $entry, 'ignored', 'No active connected account for this Instagram id');
                continue;
            }

            // Comments (+ other `changes` fields).
            foreach ($entry['changes'] ?? [] as $change) {
                $field = $change['field'] ?? null;
                $value = $change['value'] ?? [];

                if ($field === 'comments' || $field === 'live_comments') {
                    $live = $field === 'live_comments';
                    $evt = $this->store($account, $igUserId, $live ? 'live_comment' : 'comment', $field, $value);
                    ProcessInstagramEvent::dispatch('comment', $account->id, $value + ($live ? ['_live' => true] : []) + ['_evt' => $evt]);
                } elseif ($field === 'messages' || $field === 'messaging_postbacks') {
                    $this->dispatchMessaging($account->id, $value, $igUserId, $field);
                } else {
                    // messaging_seen, message_reactions, message_edit… — stored for visibility, not acted on.
                    $this->store($account, $igUserId, 'other', $field, $value, 'ignored', 'Event type not handled');
                }
            }

            // Messenger-style `messaging` array (the usual shape for DMs, postbacks and echoes).
            foreach ($entry['messaging'] ?? [] as $m) {
                $this->dispatchMessaging($account->id, $m, $igUserId, 'messages');
            }
        }

        return response('OK', 200);
    }

    /**
     * One messaging event → the right job: a button tap (`postback` / quick-reply payload), an echo of
     * a message the business sent (e.g. an agent typing in the Instagram app), or a customer DM.
     */
    private function dispatchMessaging(int $accountId, array $m, ?string $igUserId, ?string $field = 'messages'): void
    {
        $account = InstagramAccount::find($accountId);
        $senderId    = $m['sender']['id'] ?? ($m['from']['id'] ?? null);
        $recipientId = $m['recipient']['id'] ?? ($m['to']['id'] ?? null);
        $message     = $m['message'] ?? [];

        // Echo: the business is the sender; the customer is the recipient.
        if (!empty($message['is_echo']) || ($senderId && $senderId === $igUserId)) {
            $evt = $this->store($account, $igUserId, 'echo', $field, $m);
            if ($recipientId && $recipientId !== $igUserId) {
                // Delayed so our own API sends (stored with the same mid) win the race and aren't duplicated.
                ProcessInstagramEvent::dispatch('echo', $accountId, [
                    'participant_id' => $recipientId,
                    'mid'            => $message['mid'] ?? ($m['mid'] ?? null),
                    'text'           => $message['text'] ?? ($m['text'] ?? ''),
                    'attachments'    => $message['attachments'] ?? [],
                    '_evt'           => $evt,
                ])->delay(now()->addSeconds(6));
            } else {
                $this->finish($evt, 'ignored', 'Echo without a customer recipient');
            }
            return;
        }
        if (!$senderId) {
            $this->store($account, $igUserId, 'other', $field, $m, 'ignored', 'No sender id');
            return;
        }

        $postbackPayload = $m['postback']['payload'] ?? ($message['quick_reply']['payload'] ?? null);
        if ($postbackPayload) {
            $evt = $this->store($account, $igUserId, 'postback', $field, $m);
            ProcessInstagramEvent::dispatch('postback', $accountId, [
                '_evt'      => $evt,
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
            $kind = !empty($message['reply_to']['story']) ? 'story_reply' : ($referral ? 'referral' : 'message');
            $evt = $this->store($account, $igUserId, $kind, $field, $m);
            ProcessInstagramEvent::dispatch('message', $accountId, [
                '_evt'        => $evt,
                'story_reply' => !empty($message['reply_to']['story']),
                'sender_id'   => $senderId,
                'username'    => $m['sender']['username'] ?? ($m['from']['username'] ?? null),
                'mid'         => $message['mid'] ?? ($m['mid'] ?? null),
                'text'        => $message['text'] ?? ($m['text'] ?? ''),
                'attachments' => $message['attachments'] ?? [],
            ]);
        } else {
            $this->store($account, $igUserId, 'other', $field, $m, 'ignored', 'Neither a message nor a button tap');
        }
    }

    /** Persist the delivery (payloads hold no tokens) and return its id. */
    private function store(?InstagramAccount $account, ?string $igUserId, string $kind, ?string $field, array $payload, string $status = 'queued', ?string $note = null): ?int
    {
        try {
            return DB::table('instagram_webhook_events')->insertGetId([
                'company_id' => $account?->company_id, 'instagram_account_id' => $account?->id, 'ig_user_id' => $igUserId,
                'kind' => $kind, 'field' => $field, 'status' => $status, 'note' => $note,
                'payload' => json_encode($payload), 'processed_at' => $status === 'queued' ? null : now(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Instagram webhook event could not be stored: ' . $e->getMessage());   // never block the 200
            return null;
        }
    }

    private function finish(?int $id, string $status, ?string $note = null): void
    {
        if ($id) {
            DB::table('instagram_webhook_events')->where('id', $id)->update(['status' => $status, 'note' => $note, 'processed_at' => now(), 'updated_at' => now()]);
        }
    }

    private function signatureValid(Request $request): bool
    {
        $secret = config('services.instagram.app_secret');
        if (!$secret) {
            // No secret = can't verify. Only tolerated outside production (local testing).
            return !app()->environment('production');
        }
        $header = $request->header('X-Hub-Signature-256', '');
        if (!str_starts_with($header, 'sha256=')) {
            return false;
        }
        return hash_equals('sha256=' . hash_hmac('sha256', $request->getContent(), $secret), $header);
    }
}
