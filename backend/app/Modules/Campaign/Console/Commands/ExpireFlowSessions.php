<?php

namespace App\Modules\Campaign\Console\Commands;

use App\Models\Company;
use App\Models\FlowSession;
use App\Modules\Flow\Support\FlowMessages;
use App\Modules\Webhook\Services\WebhookService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Two things happen to a quiet flow session, both driven by that flow's settings (see FlowMessages):
 *  1. Follow-up — if the flow has it switched on, a customer who has been silent for N minutes gets a single
 *     "are you still there?" nudge. Replying clears it and re-arms it.
 *  2. Timeout — after the flow's idle limit (default an hour) the session ends; the customer is told (once) and
 *     typing "Hi" then starts again at the welcome menu. Only sessions that ended recently get the message,
 *     so a long-forgotten one is cleaned up silently instead of surprising someone hours later.
 */
class ExpireFlowSessions extends Command
{
    protected $signature   = 'wa-cloud:expire-flow-sessions';
    protected $description = 'WA Cloud: send "are you still there?" follow-ups, tell customers their chat session timed out, and clear it';

    public function handle(WebhookService $webhook): int
    {
        $followed = $this->sendFollowups($webhook);
        $sent = 0; $cleared = 0;

        FlowSession::where('expires_at', '<=', now())->orderBy('id')->chunkById(100, function ($sessions) use ($webhook, &$sent, &$cleared) {
            foreach ($sessions as $session) {
                $recent = $session->expires_at && $session->expires_at->gt(now()->subHours(6));
                $builderId = $webhook->sessionBuilderId($session);
                $session->delete(); // delete first: a failure while sending must never cause the message to repeat every run
                $cleared++;
                if (!$recent) { continue; }
                try {
                    $company = Company::find($session->company_id);
                    if ($company && $session->phone) {
                        $webhook->sendSessionTimeout($company, $session->phone, FlowMessages::forBuilder($builderId)['timeout_message']);
                        $sent++;
                    }
                } catch (\Throwable $e) {
                    Log::warning("Session timeout message failed for {$session->phone}: {$e->getMessage()}");
                }
            }
        });

        $this->info("Follow-ups sent: {$followed}. Cleared {$cleared} expired session(s); told {$sent} customer(s).");
        return self::SUCCESS;
    }

    /** One nudge per quiet stretch: the marker is set before sending and removed when the customer writes again. */
    private function sendFollowups(WebhookService $webhook): int
    {
        $sent = 0;
        $settings = []; // per flow, for this run

        FlowSession::where('expires_at', '>', now())->orderBy('id')->chunkById(100, function ($sessions) use ($webhook, &$sent, &$settings) {
            foreach ($sessions as $session) {
                if (!empty(($session->context ?? [])['nudged']) || !$session->phone || !$session->expires_at) { continue; }

                $builderId = $webhook->sessionBuilderId($session);
                $m = $settings[$builderId ?? 0] ??= FlowMessages::forBuilder($builderId);
                if (!$m['followup_enabled']) { continue; }

                // The session expires `timeout_minutes` after the customer's last message.
                $lastActivity = $session->expires_at->copy()->subMinutes($m['timeout_minutes']);
                if ($lastActivity->addMinutes($m['followup_after_minutes'])->isFuture()) { continue; }

                $session->update(['context' => array_merge($session->context ?? [], ['nudged' => true])]);
                try {
                    $company = Company::find($session->company_id);
                    if ($company) { $webhook->sendFollowup($company, $session->phone, $m['followup_message']); $sent++; }
                } catch (\Throwable $e) {
                    Log::warning("Follow-up message failed for {$session->phone}: {$e->getMessage()}");
                }
            }
        });

        return $sent;
    }
}
