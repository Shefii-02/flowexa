<?php

namespace App\Modules\Campaign\Console\Commands;

use App\Models\Company;
use App\Models\FlowSession;
use App\Modules\Webhook\Services\WebhookService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * A flow session ends after an hour with no message from the customer. This tells them so (once) and clears the
 * session; typing "Hi" then starts again at the welcome menu. Only sessions that ended recently get the message,
 * so a long-forgotten one is cleaned up silently instead of surprising someone hours later.
 */
class ExpireFlowSessions extends Command
{
    protected $signature   = 'wa-cloud:expire-flow-sessions';
    protected $description = 'WA Cloud: tell customers their chat session timed out after an hour of inactivity, and clear it';

    public function handle(WebhookService $webhook): int
    {
        $sent = 0; $cleared = 0;

        FlowSession::where('expires_at', '<=', now())->orderBy('id')->chunkById(100, function ($sessions) use ($webhook, &$sent, &$cleared) {
            foreach ($sessions as $session) {
                $recent = $session->expires_at && $session->expires_at->gt(now()->subHours(6));
                $session->delete(); // delete first: a failure while sending must never cause the message to repeat every run
                $cleared++;
                if (!$recent) { continue; }
                try {
                    $company = Company::find($session->company_id);
                    if ($company && $session->phone) { $webhook->sendSessionTimeout($company, $session->phone); $sent++; }
                } catch (\Throwable $e) {
                    Log::warning("Session timeout message failed for {$session->phone}: {$e->getMessage()}");
                }
            }
        });

        $this->info("Cleared {$cleared} expired session(s); told {$sent} customer(s).");
        return self::SUCCESS;
    }
}
