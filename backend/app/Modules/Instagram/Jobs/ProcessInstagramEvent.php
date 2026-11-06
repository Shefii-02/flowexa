<?php

namespace App\Modules\Instagram\Jobs;

use App\Models\InstagramAccount;
use App\Modules\Instagram\Flow\InstagramFlowEngine;
use App\Modules\Instagram\Services\InstagramAiAgent;
use App\Modules\Instagram\Services\InstagramAutomationEngine;
use App\Modules\Instagram\Services\InstagramConversationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * One unit of Instagram webhook work, off the request path so Meta gets its fast 200:
 *  - 'comment'  → flow engine first, then the legacy keyword automation
 *  - 'message'  → record the inbound DM → a waiting flow, a DM-keyword flow/automation, else the AI agent
 *  - 'postback' → a flow button tap (recorded in the inbox, then resumes the run)
 *  - 'echo'     → a message the business sent from the Instagram app, mirrored into the inbox
 */
class ProcessInstagramEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Short human note for the Activity view. */
    public ?string $outcome = null;

    public int $tries = 3;
    public int $backoff = 20;

    /**
     * @param 'comment'|'message'|'postback'|'echo' $kind
     * @param array $payload  the webhook `value` (comment) or a normalized message array
     */
    public function __construct(
        public string $kind,
        public int $accountId,
        public array $payload,
    ) {
        $this->onQueue('default');
    }

    public function handle(
        InstagramAutomationEngine $automations,
        InstagramConversationService $conversations,
        InstagramAiAgent $ai,
        InstagramFlowEngine $flows,
    ): void {
        $evt = $this->payload['_evt'] ?? null;
        try {
            $this->process($automations, $conversations, $ai, $flows);
            $this->mark($evt, 'processed', $this->outcome);
        } catch (\Throwable $e) {
            $this->mark($evt, 'failed', mb_substr($e->getMessage(), 0, 250));
            throw $e;
        }
    }

    private function mark(?int $id, string $status, ?string $note): void
    {
        if ($id) {
            \Illuminate\Support\Facades\DB::table('instagram_webhook_events')->where('id', $id)
                ->update(['status' => $status, 'note' => $note, 'processed_at' => now(), 'updated_at' => now()]);
        }
    }

    private function process(
        InstagramAutomationEngine $automations,
        InstagramConversationService $conversations,
        InstagramAiAgent $ai,
        InstagramFlowEngine $flows,
    ): void {
        $account = InstagramAccount::find($this->accountId);
        if (!$account || !$account->is_active) {
            $this->mark($this->payload['_evt'] ?? null, 'ignored', 'Account missing or inactive');
            return;
        }

        try {
            if ($this->kind === 'comment') {
                if ($flows->handleComment($account, $this->payload)) {
                    $this->outcome = 'Matched an AutoDM flow (or already handled)';
                } else {
                    $automations->handleComment($account, $this->payload);
                    $this->outcome = 'No AutoDM matched this comment (keyword/post/own comment)';
                }
                return;
            }

            $p = $this->payload;

            if ($this->kind === 'echo') {
                $conversations->recordEcho($account, (string) $p['participant_id'], (string) ($p['text'] ?? ''), $p['mid'] ?? null, $p['attachments'] ?? []);
                return;
            }

            // Meta re-delivers; process each message / tap once.
            if (!empty($p['mid']) && !Cache::add("ig-evt:{$account->id}:{$p['mid']}", 1, now()->addDay())) {
                return;
            }

            $text = (string) ($p['text'] ?? '');
            if ($this->kind === 'postback') {
                // Show the tap in the inbox (this also reopens the 24h window), then resume the flow.
                $text = (string) ($p['title'] ?? '');
                $convo = $conversations->recordInbound($account, (string) $p['sender_id'], $text, $p['mid'] ?? null, [], $p['username'] ?? null);
                if ($flows->handlePostback($account, (string) $p['sender_id'], (string) $p['payload'])) {
                    $this->outcome = 'Button tap resumed a flow';
                    return;
                }
                // Not one of ours (a plain quick reply) — treat it like the typed text.
            } else {
                $convo = $conversations->recordInbound(
                    $account,
                    (string) $p['sender_id'],
                    $text,
                    $p['mid'] ?? null,
                    $p['attachments'] ?? [],
                    $p['username'] ?? null,
                );
            }

            // A flow waiting on this person's reply, or a DM-keyword flow, takes precedence over everything else.
            $flows->noteReply($account, (string) $p['sender_id'], $text);
            if ($flows->handleReply($account, $convo, $text) || $flows->handleDm($account, $convo, $text, !empty($p['story_reply']))) {
                $this->outcome = 'Handled by an AutoDM flow';
                return;
            }
            // A thread a flow handed to a human stays with the human.
            if ($convo->needs_agent) {
                return;
            }

            // A DM keyword can also fire an automation.
            if ($text !== '') {
                $auto = $automations->pickAutomation($account, $text, null, 'dm');
                if ($auto) {
                    $dm = str_replace(['{{username}}', '{{name}}'], $convo->participant_username ?: 'there', $auto->dm_message);
                    $conversations->send($convo, $dm, 'automation');
                    $auto->forceFill(['triggered_count' => $auto->triggered_count + 1, 'last_triggered_at' => now()])->save();
                    if ($auto->handoff_to_ai) {
                        $convo->update(['ai_enabled' => true]);
                    }
                    return;
                }
            }

            $this->outcome = 'Passed to the AI agent / inbox';
            $ai->handle($account, $convo->fresh());
        } catch (\Throwable $e) {
            if (!empty($this->payload['mid'])) {
                Cache::forget("ig-evt:{$this->accountId}:{$this->payload['mid']}"); // let the retry through
            }
            Log::error('ProcessInstagramEvent failed', ['kind' => $this->kind, 'account' => $this->accountId, 'error' => $e->getMessage()]);
            throw $e;
        }
    }
}
