<?php

namespace App\Modules\Instagram\Jobs;

use App\Models\InstagramAccount;
use App\Modules\Instagram\Services\InstagramAiAgent;
use App\Modules\Instagram\Services\InstagramAutomationEngine;
use App\Modules\Instagram\Services\InstagramConversationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * One unit of Instagram webhook work, off the request path so Meta gets its fast 200:
 *  - 'comment'  → run the keyword automation engine
 *  - 'message'  → record the inbound DM, then let the AI agent reply
 */
class ProcessInstagramEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 20;

    /**
     * @param 'comment'|'message' $kind
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
    ): void {
        $account = InstagramAccount::find($this->accountId);
        if (!$account || !$account->is_active) {
            return;
        }

        try {
            if ($this->kind === 'comment') {
                $automations->handleComment($account, $this->payload);
                return;
            }

            // message
            $p = $this->payload;
            $convo = $conversations->recordInbound(
                $account,
                (string) $p['sender_id'],
                (string) ($p['text'] ?? ''),
                $p['mid'] ?? null,
                $p['attachments'] ?? [],
                $p['username'] ?? null,
            );

            // A DM keyword can also fire an automation.
            if (!empty($p['text'])) {
                $auto = $automations->pickAutomation($account, $p['text'], null, 'dm');
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

            $ai->handle($account, $convo->fresh());
        } catch (\Throwable $e) {
            Log::error('ProcessInstagramEvent failed', ['kind' => $this->kind, 'account' => $this->accountId, 'error' => $e->getMessage()]);
            throw $e;
        }
    }
}
