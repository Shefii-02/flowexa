<?php

namespace App\Modules\Instagram\Flow;

use App\Models\InstagramAccount;
use App\Models\InstagramCommentEvent;
use App\Models\InstagramConversation;
use App\Models\InstagramFlow;
use App\Models\InstagramFlowRun;
use App\Modules\Catalog\LeadIntake;
use App\Modules\Email\Services\CompanyMailerService;
use App\Modules\Instagram\Jobs\RunInstagramFlowRun;
use App\Modules\MetaAds\Services\MetaApiException;
use App\Modules\Instagram\Services\InstagramClient;
use App\Modules\Instagram\Services\InstagramConversationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Runs node-based Instagram flows. A run walks the graph until it reaches a node that has to wait
 * (button tap, typed reply, delay, rate-limit back-off) and parks there; the matching webhook /
 * timer job resumes it. `wait_token` guards every resume so Meta's duplicate deliveries and stale
 * timers are harmless.
 *
 * Postback payload: `FLOW:<run_id>:<wait_token>:<button_index>`.
 */
class InstagramFlowEngine
{
    private const MAX_STEPS = 40;

    /** Why the last handled event was skipped/started — shown in the Activity view. */
    public ?string $lastNote = null;
    /** Meta allows roughly 200 automated DMs per hour per account. */
    public const HOURLY_DM_LIMIT = 200;   // fallback; services.instagram.dm_hourly_cap wins

    public function __construct(
        private readonly InstagramClient $client,
        private readonly InstagramConversationService $conversations,
        private readonly LeadIntake $leads,
    ) {}

    // ───────────────────────────── entry points ─────────────────────────────

    /**
     * A `comments` webhook change. Returns true when a flow owns this comment (started it, or it was
     * a duplicate / our own comment) so the legacy keyword bot must not also answer it.
     */
    public function handleComment(InstagramAccount $account, array $value): bool
    {
        $commentId = $value['id'] ?? null;
        if (!$commentId || InstagramCommentEvent::where('ig_comment_id', $commentId)->exists()) {
            return (bool) $commentId;   // duplicate delivery → already handled
        }

        $fromId   = $value['from']['id'] ?? null;
        $username = $value['from']['username'] ?? null;
        $text     = (string) ($value['text'] ?? '');
        $mediaId  = $value['media']['id'] ?? ($value['media_id'] ?? null);

        if (!$fromId || $fromId === $account->ig_user_id) {
            return false;
        }
        if (!$mediaId) {
            try {
                $mediaId = $this->client->getComment($account, $commentId)['media']['id'] ?? null;
            } catch (\Throwable $e) {
                Log::debug('InstagramFlowEngine: could not fetch comment media', ['error' => $e->getMessage()]);
            }
        }

        $isLive = !empty($value['_live']);
        $flow = $this->pickFlow($account, $isLive ? 'live_comment' : 'comment', $text, $mediaId, $commentId);
        if (!$flow) {
            return false;
        }

        $event = InstagramCommentEvent::create([
            'company_id' => $account->company_id, 'instagram_account_id' => $account->id,
            'ig_comment_id' => $commentId, 'media_id' => $mediaId, 'from_id' => $fromId, 'from_username' => $username,
            'comment_text' => mb_substr($text, 0, 1000), 'action' => 'flow',
        ]);

        // One claim per (flow, person, post): two webhook jobs for the same person can run on different
        // workers at once, so the "already replied?" check + start must not interleave.
        $lock = \Illuminate\Support\Facades\Cache::lock("ig-claim:{$flow->id}:{$fromId}:" . ($mediaId ?? 'none'), 30);
        if (!$lock->get()) {
            $event->update(['action' => 'skipped_duplicate']);
            $this->lastNote = 'Skipped: another comment from this person on this post is being handled';
            return true;
        }
        try {
            if ($this->isRepeat($account, $flow, (string) $fromId, $mediaId)) {
                $event->update(['action' => 'skipped_duplicate']);
                $this->lastNote = 'Skipped: this person was already answered (' . $this->dedupePolicy($flow) . ')';
                return true;
            }
            $this->start($flow, $account, (string) $fromId, $username, [
                'comment_id' => $commentId, 'media_id' => $mediaId, 'trigger_text' => $text,
            ]);
        } finally {
            $lock->release();
        }
        return true;
    }

    /** An inbound DM that may fire a DM-keyword flow. Returns true if a flow started. */
    public function handleDm(InstagramAccount $account, InstagramConversation $convo, string $text, bool $isStoryReply = false): bool
    {
        if ($this->waitingRun($account, $convo->participant_id)) {
            return false;
        }
        $flow = $this->pickFlow($account, $isStoryReply ? 'story_reply' : 'dm', $text, null);
        if (!$flow) {
            return false;
        }
        $this->start($flow, $account, $convo->participant_id, $convo->participant_username, ['trigger_text' => $text]);
        return true;
    }

    /**
     * A button / quick-reply tap. Returns true when the payload belonged to a flow (even a stale one),
     * so it never falls through to the AI agent.
     */
    public function handlePostback(InstagramAccount $account, string $igsid, string $payload): bool
    {
        if (!preg_match('/^FLOW:(\d+):(\d+):(\d+)$/', $payload, $m)) {
            return false;
        }
        [, $runId, $token, $btn] = $m;
        $run = InstagramFlowRun::where('instagram_account_id', $account->id)->where('igsid', $igsid)->find((int) $runId);
        if (!$run) {
            return true;
        }
        $node = $run->flow?->nodeById((string) $run->current_node);
        $handle = ($node && $run->flow->hasEdge($node['id'], "btn:$btn")) ? "btn:$btn" : 'click';
        $this->resumeWaiting($run, (int) $token, ['click', 'reply'], $handle);
        return true;
    }

    /** A typed DM. Resumes a run that is waiting for a reply/tap. Returns true if a run consumed it. */
    public function handleReply(InstagramAccount $account, InstagramConversation $convo, string $text): bool
    {
        $run = $this->waitingRun($account, $convo->participant_id);
        if (!$run) {
            return false;
        }
        $run->setCtx('last_reply', $text);
        $run->save();
        // Any typed reply counts as "continue" on a button wait (also the fallback when Meta rejected the buttons).
        $this->resumeWaiting($run, $run->wait_token, ['click', 'reply'], 'reply');
        return true;
    }

    /** Timer jobs: `timeout` (wait expired), `resume` (delay elapsed), `retry` (rate-limit back-off). */
    public function handleScheduled(int $runId, int $token, string $action): void
    {
        $run = InstagramFlowRun::find($runId);
        if (!$run || $run->status !== 'waiting' || $run->wait_token !== $token) {
            return;
        }
        match ($action) {
            'timeout' => $this->resumeWaiting($run, $token, ['click', 'reply'], 'timeout'),
            'resume'  => $this->resumeWaiting($run, $token, ['delay'], 'next'),
            'retry'   => $this->resumeWaiting($run, $token, ['throttle'], null),
            default   => null,
        };
    }

    // ───────────────────────────── run lifecycle ─────────────────────────────

    private function pickFlow(InstagramAccount $account, string $trigger, string $text, ?string $mediaId, ?string $commentId = null): ?InstagramFlow
    {
        $mediaTime = null;
        $resolveTime = function () use (&$mediaTime, $account, $commentId) {
            if ($mediaTime === null && $commentId) {
                try {
                    $ts = $this->client->getComment($account, $commentId)['media']['timestamp'] ?? null;
                    $mediaTime = $ts ? strtotime($ts) : 0;
                } catch (\Throwable) { $mediaTime = 0; }
            }
            return $mediaTime ?: 0;
        };
        $flow = InstagramFlow::where('instagram_account_id', $account->id)
            ->where('is_active', true)->where('trigger_type', $trigger)
            ->orderByDesc('priority')->orderBy('id')->get()
            ->first(fn (InstagramFlow $f) => $f->matchesTrigger($text, $mediaId, $resolveTime));
        // "Next post/reel": the first matching post published after creation becomes this flow's post.
        if ($flow && $mediaId && ($flow->triggerNode()['data']['media_scope'] ?? '') === 'next' && empty($flow->triggerNode()['data']['locked_media_id'])) {
            $nodes = $flow->nodes;
            foreach ($nodes as &$n) { if (str_starts_with($n['type'], 'trigger_')) $n['data']['locked_media_id'] = $mediaId; }
            $flow->update(['nodes' => $nodes]);
        }
        return $flow;
    }

    /**
     * once_per_post (default for wizard flows): the same person is answered once per post.
     * once_per_24h: same, but they can trigger it again after 24 hours.
     * once_per_flow: once ever per flow (older flows' `reply_once_per_user`). always: every comment.
     */
    private function dedupePolicy(InstagramFlow $flow): string
    {
        $d = $flow->triggerNode()['data'] ?? [];
        return $d['dedupe'] ?? (($d['reply_once_per_user'] ?? true) ? 'once_per_flow' : 'always');
    }

    private function isRepeat(InstagramAccount $account, InstagramFlow $flow, string $igsid, ?string $mediaId): bool
    {
        $policy = $this->dedupePolicy($flow);
        if ($policy === 'always') {
            return false;
        }
        $q = $flow->runs()->where('igsid', $igsid)
            // A run that failed before sending anything must not lock the person out for good.
            ->where(fn ($q) => $q->where('status', '!=', 'failed')->orWhere('context->private_reply_sent', true));
        if ($policy !== 'once_per_flow' && $mediaId !== null) {
            $q->where('context->media_id', $mediaId);
            if ($policy === 'once_per_24h') {
                $q->where('created_at', '>=', now()->subDay());
            }
        }
        $prior = $q->latest('id')->first();
        if (!$prior) {
            return false;
        }

        // "I didn't get the DM, so I deleted my comment and posted it again": if the comment that started
        // the earlier run no longer exists, treat the new comment as a fresh request and send again.
        if ($prior->comment_id && $this->commentGone($account, $prior->comment_id)) {
            if ($prior->status === 'waiting') {
                $this->finish($prior, 'failed', 'Superseded: the comment was deleted and posted again.');   // stale buttons/timers go inert
            }
            $this->lastNote = 'Resent: the earlier comment was deleted and re-posted';
            return false;
        }
        return true;
    }

    /** True only when Instagram says the comment does not exist; any other error counts as "still there". */
    private function commentGone(InstagramAccount $account, string $commentId): bool
    {
        try {
            $this->client->getComment($account, $commentId);
            return false;
        } catch (MetaApiException $e) {
            return $e->metaCode === 100 || $e->httpStatus === 404 || str_contains(mb_strtolower($e->getMessage()), 'does not exist');
        } catch (\Throwable) {
            return false;
        }
    }

    /** Remember what a person typed while a run is parked on a delay — drives the "no response" follow-up condition. */
    public function noteReply(InstagramAccount $account, string $igsid, string $text): void
    {
        $run = InstagramFlowRun::where('instagram_account_id', $account->id)->where('igsid', $igsid)
            ->where('status', 'waiting')->where('wait_kind', 'delay')->latest('id')->first();
        if ($run) {
            $run->setCtx('replied', true);
            $run->save();
        }
    }

    private function bump(InstagramFlowRun $run, string $column): void
    {
        InstagramFlow::where('id', $run->instagram_flow_id)->increment($column);
    }

    /** Swap a button URL for a tracked redirect (https://api.teamzo.io/r/{token}). */
    private function trackedUrl(InstagramFlowRun $run, string $url): string
    {
        $token = \Illuminate\Support\Str::random(12);
        DB::table('instagram_link_clicks')->insert([
            'token' => $token, 'instagram_flow_id' => $run->instagram_flow_id, 'instagram_flow_run_id' => $run->id,
            'url' => $url, 'created_at' => now(), 'updated_at' => now(),
        ]);
        return rtrim(config('services.instagram.link_host', 'https://api.teamzo.io'), '/') . '/r/' . $token;
    }

    private function waitingRun(InstagramAccount $account, string $igsid): ?InstagramFlowRun
    {
        return InstagramFlowRun::where('instagram_account_id', $account->id)->where('igsid', $igsid)
            ->where('status', 'waiting')->whereIn('wait_kind', ['click', 'reply'])
            ->latest('id')->first();
    }

    public function start(InstagramFlow $flow, InstagramAccount $account, string $igsid, ?string $username, array $context): InstagramFlowRun
    {
        $convo = $this->conversations->thread($account, $igsid, $username);
        $trigger = $flow->triggerNode();
        $run = InstagramFlowRun::create([
            'company_id' => $account->company_id, 'instagram_account_id' => $account->id,
            'instagram_flow_id' => $flow->id, 'instagram_conversation_id' => $convo->id,
            'igsid' => $igsid, 'comment_id' => $context['comment_id'] ?? null,
            'status' => 'running', 'current_node' => $trigger['id'],
            'context' => $context + ['username' => $username],
        ]);
        $flow->forceFill(['triggered_count' => $flow->triggered_count + 1, 'last_triggered_at' => now()])->save();

        $this->execute($run, $flow->nextNodeId($trigger['id']));
        return $run;
    }

    /**
     * Atomically claim a parked run (status + token + wait kind must match), then continue it through
     * `$handle`. A null handle re-executes the current node (rate-limit retry).
     */
    private function resumeWaiting(InstagramFlowRun $run, int $token, array $kinds, ?string $handle): void
    {
        $claimed = DB::transaction(function () use ($run, $token, $kinds) {
            $fresh = InstagramFlowRun::lockForUpdate()->find($run->id);
            if (!$fresh || $fresh->status !== 'waiting' || $fresh->wait_token !== $token || !in_array($fresh->wait_kind, $kinds, true)) {
                return null;
            }
            $fresh->update(['status' => 'running', 'wait_kind' => null, 'wait_expires_at' => null]);
            return $fresh;
        });
        if (!$claimed) {
            return;
        }

        $flow = $claimed->flow;
        if (!$flow) {
            $this->finish($claimed, 'failed', 'The flow was deleted.');
            return;
        }
        $nodeId = (string) $claimed->current_node;
        // Retry, and answers to a collect_input question, re-enter the same node.
        $node = $flow->nodeById($nodeId);
        if ($handle === null || (($node['type'] ?? null) === 'collect_input' && in_array($handle, ['reply', 'click'], true))) {
            $this->execute($claimed, $nodeId);
            return;
        }
        // A typed reply on a button wait takes the "click" edge; a wait_reply node takes "reply".
        $next = $flow->nextNodeId($nodeId, $handle)
            ?? ($handle === 'reply' ? $flow->nextNodeId($nodeId, 'click') : null)
            ?? ($handle === 'click' ? $flow->nextNodeId($nodeId, 'reply') : null);
        $this->execute($claimed, $next);
    }

    /** Walk the graph from `$nodeId` until a node parks the run or the path ends. */
    private function execute(InstagramFlowRun $run, ?string $nodeId): void
    {
        $flow  = $run->flow;
        $convo = $run->conversation ?? $this->conversations->thread($run->account, $run->igsid);

        for ($steps = 0; $nodeId !== null; $steps++) {
            if ($steps >= self::MAX_STEPS) {
                $this->finish($run, 'failed', 'Flow exceeded the step limit (loop without a wait?).');
                return;
            }
            $node = $flow->nodeById($nodeId);
            if (!$node) {
                break;
            }
            $run->current_node = $nodeId;
            $run->save();

            try {
                $res = $this->runNode($run, $node, $convo->fresh());
            } catch (MetaApiException $e) {
                // Rate limit → back off and retry the same node; token/permission → flag the account; 551 → user unreachable.
                if ($e->isRateLimit() && $run->ctx('rate_retries', 0) < 5) {
                    $run->setCtx('rate_retries', $run->ctx('rate_retries', 0) + 1);
                    $run->save();
                    $this->park($run, 'throttle', 15 * 60, null);
                    return;
                }
                if ($e->isAuthError() || in_array($e->metaCode, [10, 200], true)) {
                    if ($e->isAuthError()) {
                        $run->account?->update(['status' => 'token_expired']);
                    }
                    $this->finish($run, 'failed', 'Instagram rejected the connection (permission/token). Reconnect the account.');
                    return;
                }
                if ($e->metaCode === 551) {
                    $this->finish($run, 'failed', 'This user can\'t receive messages right now (551).');
                    return;
                }
                Log::warning('InstagramFlowEngine: Graph error', ['run' => $run->id, 'node' => $nodeId, 'code' => $e->metaCode, 'fbtrace' => $e->fbtraceId]);
                $this->finish($run, 'failed', $e->getMessage());
                return;
            } catch (\Throwable $e) {
                Log::warning('InstagramFlowEngine: node failed', ['run' => $run->id, 'node' => $nodeId, 'error' => $e->getMessage()]);
                $this->finish($run, 'failed', $e->getMessage());
                return;
            }

            if (isset($res['fail'])) {
                $this->finish($run, 'failed', $res['fail']);
                return;
            }
            if (isset($res['end'])) {
                $this->finish($run, 'done');
                return;
            }
            if (isset($res['wait'])) {
                $this->park($run, $res['wait'], $res['seconds'] ?? null, $res['timeout_minutes'] ?? null);
                return;
            }
            $nodeId = $flow->nextNodeId($nodeId, $res['handle'] ?? 'next');
        }
        $this->finish($run, 'done');
    }

    private function park(InstagramFlowRun $run, string $kind, ?int $seconds, ?int $timeoutMinutes): void
    {
        $token = $run->wait_token + 1;
        $expires = match (true) {
            $seconds !== null        => now()->addSeconds($seconds),
            (bool) $timeoutMinutes   => now()->addMinutes($timeoutMinutes),
            default                  => now()->addHours(24),   // the 24h window is the natural limit
        };
        $run->forceFill(['status' => 'waiting', 'wait_kind' => $kind, 'wait_token' => $token, 'wait_expires_at' => $expires])->save();

        if ($kind === 'throttle') {
            // FIFO queue: keep the original queue time across re-parks. The scheduled drainer
            // (instagram:drain-queue) resumes runs oldest-first; no per-run timers, so order is preserved.
            if (!$run->queued_at) {
                $run->forceFill(['queued_at' => now()])->save();
            }
        } elseif ($kind === 'delay') {
            RunInstagramFlowRun::dispatch($run->id, $token, 'resume')->delay($expires);
        } elseif ($timeoutMinutes || $run->flow->hasEdge((string) $run->current_node, 'timeout')) {
            RunInstagramFlowRun::dispatch($run->id, $token, 'timeout')->delay($expires);
        }
    }

    private function finish(InstagramFlowRun $run, string $status, ?string $error = null): void
    {
        $run->forceFill(['status' => $status, 'error' => $error, 'finished_at' => now(), 'wait_kind' => null, 'wait_expires_at' => null])->save();
    }

    // ───────────────────────────── node handlers ─────────────────────────────

    /** @return array{handle?:string,wait?:string,seconds?:int,timeout_minutes?:int,end?:bool,fail?:string} */
    private function runNode(InstagramFlowRun $run, array $node, InstagramConversation $convo): array
    {
        $d = $node['data'] ?? [];
        $account = $run->account;

        return match ($node['type']) {
            'trigger_comment', 'trigger_dm' => [],
            'public_reply'     => $this->publicReply($run, $account, $d),
            'private_reply'    => $this->sendNode($run, $account, $convo, $d, privateReply: true),
            'send_dm'          => $this->sendNode($run, $account, $convo, $d),
            'wait_reply'       => ['wait' => 'reply', 'timeout_minutes' => (int) ($d['timeout_minutes'] ?? 0) ?: null],
            'condition_follow' => $this->conditionFollow($run, $account, $convo),
            'condition_check'  => ['handle' => $this->check($run, $convo, $d) ? 'true' : 'false'],
            'collect_input'    => $this->collectInput($run, $account, $convo, $node),
            'delay'            => $this->delay($d),
            'crm_action'       => $this->crmAction($run, $account, $convo, $d),
            'send_email'       => $this->sendEmail($run, $account, $convo, $d),
            'http_request'     => $this->httpRequest($run, $convo, $d),
            'human_handover'   => $this->handover($run, $account, $convo, $d),
            default            => ['fail' => "Unknown node type {$node['type']}"],
        };
    }

    private function publicReply(InstagramFlowRun $run, InstagramAccount $account, array $d): array
    {
        $commentId = $run->comment_id;
        $texts = array_values(array_filter($d['texts'] ?? [], fn ($t) => trim((string) $t) !== ''));
        if (!$commentId || !$texts) {
            return [];
        }
        try {
            $this->client->replyToComment($account, $commentId, $this->fill($texts[array_rand($texts)], $run));
        } catch (\Throwable $e) {
            // A failed public comment must not stop the DM.
            Log::warning('InstagramFlowEngine: public reply failed', ['run' => $run->id, 'error' => $e->getMessage()]);
        }
        return [];
    }

    /** Send a DM node (text / image / buttons / quick replies). Parks the run when it offers something to tap. */
    private function sendNode(InstagramFlowRun $run, InstagramAccount $account, InstagramConversation $convo, array $d, bool $privateReply = false): array
    {
        $raw = (string) ($d['text'] ?? '');
        if (!empty($d['retry_text']) && (int) $run->ctx('follow_tries', 0) > 0) $raw = $d['retry_text'];
        $text = $this->fill($raw, $run);
        $buttons = array_slice(array_values(array_filter($d['buttons'] ?? [], fn ($b) => trim((string) ($b['title'] ?? '')) !== '')), 0, 3);
        $quick   = array_slice(array_values(array_filter($d['quick_replies'] ?? [], fn ($b) => trim((string) ($b['title'] ?? '')) !== '')), 0, 13);

        // Number postback buttons / quick replies so the tap can pick its own edge (btn:N).
        $btnObjs = []; $storedButtons = [];
        foreach ($buttons as $i => $b) {
            $title = mb_substr($b['title'], 0, 20);
            if (($b['type'] ?? 'postback') === 'url' && !empty($b['url'])) {
                // {{profile_url}} is the BUSINESS profile (the account being followed), not the commenter's own.
                $url = str_replace('{{profile_url}}', 'https://www.instagram.com/' . ltrim((string) $account->username, '@') . '/', $b['url']);
                $btnObjs[] = ['type' => 'web_url', 'title' => $title, 'url' => ($b['track'] ?? true) ? $this->trackedUrl($run, $url) : $url];
            } else {
                $btnObjs[] = ['type' => 'postback', 'title' => $title, 'payload' => $this->payload($run, $i)];
            }
            $storedButtons[] = ['title' => $title, 'type' => $btnObjs[$i]['type'] === 'web_url' ? 'url' : 'postback', 'url' => $b['url'] ?? null];
        }
        $qrObjs = [];
        foreach ($quick as $i => $q) {
            $qrObjs[] = ['title' => $q['title'], 'payload' => $this->payload($run, $i)];
        }
        $waits = (bool) array_filter($btnObjs, fn ($b) => $b['type'] === 'postback') || $qrObjs;
        $stored = array_filter(['buttons' => $storedButtons, 'quick_replies' => array_column($quick, 'title'), 'image_url' => $d['image_url'] ?? null]);

        if ($err = $this->throttled($account, $run)) {
            return ['wait' => 'throttle', 'seconds' => $err];
        }

        // The private reply is the one message allowed before the user has interacted.
        $useComment = $privateReply && $run->comment_id && !$run->ctx('private_reply_sent');
        if (!$useComment && !$convo->withinMessagingWindow()) {
            $this->conversations->storeOutbound($convo, $text, 'flow', null, 'failed', 'Outside the 24-hour messaging window');
            return ['fail' => 'Outside the 24-hour messaging window — the user has not messaged in the last 24h.'];
        }
        $recipient = $useComment ? ['comment_id' => $run->comment_id] : ['id' => $run->igsid];

        $messageId = null;
        if (!empty($d['image_url']) && !$useComment) {
            $this->client->sendMessage($account, $recipient, InstagramClient::imageMessage($d['image_url']));
            $this->recordSend($account);
            $this->bump($run, 'dms_sent');
        }
        // Media attachment (image / video / audio) and generic-template card.
        if (!empty($d['attachment']['url']) && in_array($d['attachment']['type'] ?? '', ['image', 'video', 'audio', 'file'], true) && str_starts_with((string) $d['attachment']['url'], 'https://')) {
            $res = $this->client->sendMessage($account, $recipient, ['attachment' => ['type' => $d['attachment']['type'], 'payload' => ['url' => $d['attachment']['url']]]]);
            $this->recordSend($account);
            $this->bump($run, 'dms_sent');
            $this->conversations->storeOutbound($convo, '[' . $d['attachment']['type'] . ']', 'flow', $res['message_id'] ?? null, 'sent', null, ['image_url' => $d['attachment']['url']]);
            if ($useComment) { $run->setCtx('private_reply_sent', true); $run->save(); }
            if ($text === '' && !$btnObjs) return [];
            $recipient = ['id' => $run->igsid];   // a comment-id recipient is single-use
        } elseif (!empty($d['card'])) {
            $card = $d['card'];
            $res = $this->client->sendMessage($account, $recipient, ['attachment' => ['type' => 'template', 'payload' => [
                'template_type' => 'generic',
                'elements' => [array_filter([
                    'title' => mb_substr($this->fill((string) ($card['title'] ?? ''), $run), 0, 80),
                    'subtitle' => mb_substr($this->fill((string) ($card['subtitle'] ?? ''), $run), 0, 80),
                    'image_url' => $card['image_url'] ?? null,
                    'buttons' => array_slice($btnObjs, 0, 3) ?: null,
                ])],
            ]]]);
            $this->recordSend($account);
            $this->bump($run, 'dms_sent');
            $this->conversations->storeOutbound($convo, (string) ($card['title'] ?? 'Card'), 'flow', $res['message_id'] ?? null, 'sent', null, $stored ?: null);
            if ($useComment) { $run->setCtx('private_reply_sent', true); $run->save(); }
            return $waits ? ['wait' => 'click', 'timeout_minutes' => (int) ($d['timeout_minutes'] ?? 0) ?: null] : [];
        }
        if ($text !== '' || $btnObjs || $qrObjs) {
            $res = $this->deliver($account, $recipient, $text, $btnObjs, $qrObjs);
            $messageId = $res['message_id'] ?? null;
            $this->recordSend($account);
            $this->bump($run, 'dms_sent');
            $this->conversations->storeOutbound($convo, $text, 'flow', $messageId, 'sent', null, $stored ?: null);
        }
        if ($useComment) {
            $run->setCtx('private_reply_sent', true);
            $run->save();
        }

        return $waits
            ? ['wait' => 'click', 'timeout_minutes' => (int) ($d['timeout_minutes'] ?? 0) ?: null]
            : [];
    }

    /**
     * Send text + buttons, degrading if Meta rejects the template (it sometimes does for private
     * replies): button template → text + quick replies → plain text with URLs inlined.
     */
    private function deliver(InstagramAccount $account, array $recipient, string $text, array $buttons, array $quickReplies): array
    {
        if (!$buttons && !$quickReplies) {
            return $this->client->sendMessage($account, $recipient, ['text' => $text]);
        }
        if ($buttons && $text !== '') {
            try {
                return $this->client->sendMessage($account, $recipient, InstagramClient::buttonTemplate($text, $buttons));
            } catch (\Throwable $e) {
                Log::info('InstagramFlowEngine: button template rejected, falling back', ['error' => $e->getMessage()]);
            }
        }
        $replies = $quickReplies ?: array_map(fn ($b) => ['title' => $b['title'], 'payload' => $b['payload']],
            array_values(array_filter($buttons, fn ($b) => $b['type'] === 'postback')));
        $links = collect($buttons)->where('type', 'web_url')->map(fn ($b) => "{$b['title']}: {$b['url']}")->implode("\n");
        $body = trim($text . ($links !== '' ? "\n\n{$links}" : ''));
        if ($replies) {
            try {
                return $this->client->sendMessage($account, $recipient, InstagramClient::quickReplies($body, $replies));
            } catch (\Throwable $e) {
                Log::info('InstagramFlowEngine: quick replies rejected, sending plain text', ['error' => $e->getMessage()]);
            }
        }
        return $this->client->sendMessage($account, $recipient, ['text' => $body]);
    }

    private function conditionFollow(InstagramFlowRun $run, InstagramAccount $account, InstagramConversation $convo): array
    {
        try {
            $profile = $this->client->userProfile($account, $run->igsid);
        } catch (\Throwable $e) {
            Log::warning('InstagramFlowEngine: follower check failed', ['run' => $run->id, 'error' => $e->getMessage()]);
            return ['handle' => $run->flow->hasEdge((string) $run->current_node, 'error') ? 'error' : 'false'];
        }
        $follows = (bool) ($profile['is_user_follow_business'] ?? false);
        $convo->update(['follows_business' => $follows, 'follow_checked_at' => now()]);
        if (isset($profile['follower_count'])) {
            $convo->update(['collected' => array_merge($convo->collected ?? [], ['follower_count' => (string) $profile['follower_count']])]);
        }
        $tries = (int) $run->ctx('follow_tries', 0);
        if ($follows) {
            if ($tries > 0) { $run->setCtx('gained_follower', true); $run->save(); $this->bump($run, 'new_followers'); }
            return ['handle' => 'true'];
        }
        $max = (int) (($run->flow->nodeById((string) $run->current_node)['data']['max_retries'] ?? 0));
        if ($max > 0 && $tries >= $max && $run->flow->hasEdge((string) $run->current_node, 'exhausted')) {
            return ['handle' => 'exhausted'];
        }
        $run->setCtx('follow_tries', $tries + 1);
        $run->save();
        if (!empty($profile['username']) && !$convo->participant_username) {
            $convo->update(['participant_username' => $profile['username']]);
        }
        return ['handle' => $follows ? 'true' : 'false'];
    }

    private function check(InstagramFlowRun $run, InstagramConversation $convo, array $d): bool
    {
        $value = mb_strtolower(trim((string) ($d['value'] ?? '')));
        $actual = match ($d['check'] ?? 'tag') {
            'tag'        => $value !== '' ? in_array($value, array_map('mb_strtolower', $convo->tags ?? []), true) : !empty($convo->tags),
            'field'      => (string) (($convo->collected ?? [])[$d['key'] ?? ''] ?? ''),
            'last_reply' => (string) $run->ctx('last_reply', ''),
            'replied'    => (bool) $run->fresh()?->ctx('replied', false),
            'link_clicked' => (bool) $run->fresh()?->ctx('link_clicked', false),
            default      => '',
        };
        if (is_bool($actual)) {
            return $actual;
        }
        $actual = mb_strtolower(trim($actual));
        return match ($d['op'] ?? 'equals') {
            'exists'   => $actual !== '',
            'contains' => $value !== '' && str_contains($actual, $value),
            default    => $actual === $value,
        };
    }

    /**
     * Ask a question and wait for the typed answer. Park → on the next reply, validate; valid answers
     * are saved to the contact field and flow on, invalid ones re-ask (up to `max_retries`, then the
     * `invalid` edge).
     */
    private function collectInput(InstagramFlowRun $run, InstagramAccount $account, InstagramConversation $convo, array $node): array
    {
        $d = $node['data'] ?? [];
        $answering = $run->ctx("collect_pending") === $node['id'];

        if ($answering) {
            $answer = trim((string) $run->ctx('last_reply', ''));
            $value = $this->validateInput($answer, $d['validation'] ?? 'text');
            $run->setCtx('collect_pending', null);
            if ($value !== null) {
                $field = preg_replace('/[^a-z0-9_]/', '', strtolower($d['field'] ?? 'answer')) ?: 'answer';
                $convo->update(['collected' => array_merge($convo->collected ?? [], [$field => $value])]);
                $run->save();
                return ['handle' => 'next'];
            }
            $tries = (int) $run->ctx("collect_tries_{$node['id']}", 0) + 1;
            $run->setCtx("collect_tries_{$node['id']}", $tries);
            if ($tries > (int) ($d['max_retries'] ?? 2)) {
                $run->save();
                return ['handle' => 'invalid'];
            }
            $prompt = $d['retry_prompt'] ?? "That doesn't look right. " . ($d['prompt'] ?? 'Please try again.');
        } else {
            $prompt = $d['prompt'] ?? 'Please reply with your answer.';
        }

        $this->guardWindow($convo);
        if ($wait = $this->throttled($account, $run)) {
            return ['wait' => 'throttle', 'seconds' => $wait];
        }
        $text = $this->fill($prompt, $run);
        $res = $this->client->sendMessage($account, ['id' => $run->igsid], ['text' => $text]);
        $this->recordSend($account);
        $this->conversations->storeOutbound($convo, $text, 'flow', $res['message_id'] ?? null, 'sent', null);
        $run->setCtx('collect_pending', $node['id']);
        $run->save();
        return ['wait' => 'reply', 'timeout_minutes' => (int) ($d['timeout_minutes'] ?? 0) ?: null];
    }

    private function validateInput(string $answer, string $type): ?string
    {
        return match ($type) {
            'email' => filter_var($answer, FILTER_VALIDATE_EMAIL) ? strtolower($answer) : null,
            'phone' => (function () use ($answer) {
                $digits = preg_replace('/\D/', '', $answer);
                return strlen($digits) >= 8 && strlen($digits) <= 15 ? $digits : null;
            })(),
            default => $answer !== '' ? mb_substr($answer, 0, 500) : null,
        };
    }

    private function delay(array $d): array
    {
        $seconds = max(1, (int) ($d['amount'] ?? 5)) * match ($d['unit'] ?? 'seconds') { 'hours' => 3600, 'minutes' => 60, default => 1 };
        // A delay can't outlive the 24h messaging window.
        return ['wait' => 'delay', 'seconds' => min($seconds, 23 * 3600)];
    }

    private function crmAction(InstagramFlowRun $run, InstagramAccount $account, InstagramConversation $convo, array $d): array
    {
        $tag = trim((string) ($d['tag'] ?? ''));
        switch ($d['action'] ?? 'add_tag') {
            case 'add_tag':
                if ($tag !== '') {
                    $convo->update(['tags' => array_values(array_unique(array_merge($convo->tags ?? [], [$tag])))]);
                }
                break;
            case 'remove_tag':
                $convo->update(['tags' => array_values(array_diff($convo->tags ?? [], [$tag]))]);
                break;
            case 'create_lead':
                $collected = $convo->collected ?? [];
                if (!$convo->lead_id && (!empty($collected['phone']) || !empty($collected['email']))) {
                    $result = $this->leads->capture(
                        $account->company, $collected + ['name' => $convo->participant_username], 'instagram_dm', "ig:{$convo->id}",
                        originType: 'instagram_account', originId: $account->id,
                        originLabel: $account->username ? "@{$account->username}" : $account->name,
                    );
                    if ($result['contact']) {
                        $convo->update([
                            'contact_id' => $result['contact']->id, 'lead_id' => $result['lead']?->id, 'lead_created_at' => now(),
                        ]);
                        $this->bump($run, 'leads');
                    }
                }
                break;
        }
        return [];
    }

    /** Wizard "Send Automated Email": uses the company's own SMTP integration; best-effort (never stops the run). */
    private function sendEmail(InstagramFlowRun $run, InstagramAccount $account, InstagramConversation $convo, array $d): array
    {
        $email = ($convo->collected ?? [])['email'] ?? null;
        if (!$email || !$account->company) {
            return [];
        }
        $html = nl2br(e($this->fill((string) ($d['body'] ?? ''), $run)));
        $r = app(CompanyMailerService::class)->send($account->company, $email, $run->ctx('username'), $this->fill((string) ($d['subject'] ?? ''), $run), $html, 'instagram_autodm', $convo->contact_id);
        if (!$r['success']) {
            Log::info('InstagramFlowEngine: automated email failed', ['run' => $run->id, 'error' => $r['message']]);
        }
        return [];
    }

    private function httpRequest(InstagramFlowRun $run, InstagramConversation $convo, array $d): array
    {
        $url = $this->fill((string) ($d['url'] ?? ''), $run);
        if (!$this->safeUrl($url)) {
            return ['handle' => 'error'];
        }
        try {
            $method = strtolower($d['method'] ?? 'post') === 'get' ? 'get' : 'post';
            $payload = [
                'igsid' => $run->igsid, 'username' => $convo->participant_username, 'comment_id' => $run->comment_id,
                'fields' => $convo->collected ?? [], 'tags' => $convo->tags ?? [], 'follows_business' => $convo->follows_business,
            ];
            $res = Http::timeout(8)->withoutRedirecting()->{$method}($url, $payload);
            $run->setCtx('http_status', $res->status());
            $run->save();
            return ['handle' => $res->successful() ? 'success' : 'error'];
        } catch (\Throwable $e) {
            return ['handle' => 'error'];
        }
    }

    /** Company-supplied URL → refuse anything that resolves to a private / reserved address (SSRF guard). */
    private function safeUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host'])) {
            return false;
        }
        $ips = filter_var($parts['host'], FILTER_VALIDATE_IP) ? [$parts['host']] : (gethostbynamel($parts['host']) ?: []);
        if (!$ips) {
            return false;
        }
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
        }
        return true;
    }

    private function handover(InstagramFlowRun $run, InstagramAccount $account, InstagramConversation $convo, array $d): array
    {
        $convo->update([
            'ai_enabled'  => false,
            'needs_agent' => true,
            'status'      => 'open',
            'tags'        => array_values(array_unique(array_merge($convo->tags ?? [], ['needs-agent']))),
        ]);
        if (!empty($d['message']) && $convo->withinMessagingWindow()) {
            $text = $this->fill($d['message'], $run);
            $res = $this->client->sendMessage($account, ['id' => $run->igsid], ['text' => $text]);
            $this->conversations->storeOutbound($convo, $text, 'flow', $res['message_id'] ?? null, 'sent', null);
        }
        return ['end' => true];
    }

    // ───────────────────────────── helpers ─────────────────────────────

    private function payload(InstagramFlowRun $run, int $index): string
    {
        // The token this tap will be valid for = the one the run takes when it parks right after sending.
        return "FLOW:{$run->id}:" . ($run->wait_token + 1) . ":{$index}";
    }

    private function guardWindow(InstagramConversation $convo): void
    {
        if (!$convo->withinMessagingWindow()) {
            throw new \RuntimeException('Outside the 24-hour messaging window.');
        }
    }

    private function rateKey(InstagramAccount $account): string
    {
        return "ig-dm:{$account->id}";
    }

    /** Count one automated DM against the hourly window (Meta) and the plan's monthly allowance. */
    private function recordSend(InstagramAccount $account): void
    {
        RateLimiter::hit($this->rateKey($account), 3600);
        try { $cap = $account->company?->plan?->max_instagram_dms_monthly; } catch (\Throwable) { $cap = null; }
        if ($cap !== null) {
            $key = "ig-dm-month:{$account->company_id}:" . now()->format('Ym');
            \Illuminate\Support\Facades\Cache::add($key, 0, now()->endOfMonth()->addDay());
            \Illuminate\Support\Facades\Cache::increment($key);
        }
    }

    private function hourlyCap(): int
    {
        return (int) config('services.instagram.dm_hourly_cap', self::HOURLY_DM_LIMIT);
    }

    /** Seconds until the hourly window frees up, or null when there is budget. */
    private function windowFull(InstagramAccount $account): ?int
    {
        return RateLimiter::tooManyAttempts($this->rateKey($account), $this->hourlyCap())
            ? max(30, RateLimiter::availableIn($this->rateKey($account)))
            : null;
    }

    /**
     * Whether this send must wait (seconds) — hourly budget spent, or older runs are already queued for
     * this account (first come, first served; the drainer resumes those with `from_queue` set).
     * The plan's monthly allowance is a hard stop: waiting wouldn't help.
     */
    private function throttled(InstagramAccount $account, ?InstagramFlowRun $run = null): ?int
    {
        try { $cap = $account->company?->plan?->max_instagram_dms_monthly; } catch (\Throwable) { $cap = null; }
        if ($cap !== null
            && (int) \Illuminate\Support\Facades\Cache::get("ig-dm-month:{$account->company_id}:" . now()->format('Ym'), 0) >= $cap) {
            throw new \RuntimeException("Monthly Instagram DM limit ({$cap}) reached for your plan.");
        }
        if ($wait = $this->windowFull($account)) {
            return $wait;
        }
        if ($run && !$run->ctx('from_queue') && $this->hasBacklog($account, $run->id)) {
            return 30;
        }
        return null;
    }

    private function hasBacklog(InstagramAccount $account, ?int $exceptRunId = null): bool
    {
        return InstagramFlowRun::where('instagram_account_id', $account->id)
            ->where('status', 'waiting')->where('wait_kind', 'throttle')
            ->when($exceptRunId, fn ($q) => $q->where('id', '!=', $exceptRunId))->exists();
    }

    /**
     * Send what the hourly cap held back, oldest first. Stops at the first run that parks again
     * (budget gone) so later arrivals never overtake earlier ones. Comment runs older than Instagram's
     * 7-day private-reply limit are closed as expired. Returns how many runs were resumed.
     */
    public function drainQueue(InstagramAccount $account, int $max = 200): int
    {
        $resumed = 0;
        $runs = InstagramFlowRun::where('instagram_account_id', $account->id)
            ->where('status', 'waiting')->where('wait_kind', 'throttle')
            ->orderBy('queued_at')->orderBy('id')->limit($max)->get();

        foreach ($runs as $run) {
            if ($run->comment_id && !$run->ctx('private_reply_sent') && $run->created_at?->lt(now()->subDays(7))) {
                $this->finish($run, 'failed', 'Expired in the queue: Instagram only allows a private reply within 7 days of the comment.');
                continue;
            }
            if ($this->windowFull($account)) {
                break;
            }
            $run->setCtx('from_queue', true);
            $run->save();
            $this->resumeWaiting($run, $run->wait_token, ['throttle'], null);
            $resumed++;

            $fresh = $run->fresh();
            if ($fresh && $fresh->status === 'waiting' && $fresh->wait_kind === 'throttle') {
                break;   // parked again: budget is gone
            }
        }
        return $resumed;
    }

    /** {{username}} / {{name}} / {{any_collected_field}} substitution. */
    private function fill(string $template, InstagramFlowRun $run): string
    {
        $convo = $run->conversation()->first();   // fresh: earlier nodes may have just saved a field
        if ($convo && str_contains($template, 'follower_count') && !isset(($convo->collected ?? [])['follower_count'])) {
            try {
                $fc = $this->client->userProfile($run->account, $run->igsid)['follower_count'] ?? null;
                if ($fc !== null) { $convo->update(['collected' => array_merge($convo->collected ?? [], ['follower_count' => (string) $fc])]); }
            } catch (\Throwable) {}
        }
        $vars = ($convo?->collected ?? []) + [
            'username' => $run->ctx('username') ? '@' . ltrim($run->ctx('username'), '@') : 'there',
            'name'     => $run->ctx('username') ?: 'there',
        ];
        return preg_replace_callback('/\{\{\s*(\w+)\s*\}\}/', fn ($m) => (string) ($vars[$m[1]] ?? ''), $template);
    }
}
