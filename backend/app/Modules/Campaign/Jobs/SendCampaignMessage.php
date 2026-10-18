<?php

namespace App\Modules\Campaign\Jobs;

use App\Models\Campaign;
use App\Models\CampaignContact;
use App\Modules\Campaign\Services\CampaignMessageSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sends ONE campaign contact. ProcessCampaignBatch dispatches these with a delay per contact
 * (the campaign's send gap), so messages leave one by one over time instead of in a burst and
 * no worker is ever blocked sleeping between sends.
 */
class SendCampaignMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const MAX_SENDS = 3;  // sends per contact: first try + network retries
    public int $tries   = 8;      // headroom: every release() for a network retry counts as an attempt
    public int $timeout = 60;

    public function __construct(private readonly int $campaignContactId) {}

    public function handle(CampaignMessageSender $sender): void
    {
        // The same row can be queued twice (pause -> resume, stuck-row recovery): only one may send.
        $lock = Cache::lock("campaign-contact-send:{$this->campaignContactId}", 90);
        if (!$lock->get()) { return; }

        try {
            $this->send($sender);
        } finally {
            $lock->release();
        }
    }

    private function send(CampaignMessageSender $sender): void
    {
        $row = CampaignContact::find($this->campaignContactId);
        if (!$row || $row->status !== 'pending') { return; } // already sent / handled elsewhere

        $campaign = Campaign::with(['template', 'waPhoneNumber', 'company'])->find($row->campaign_id);
        if (!$campaign || $campaign->status !== 'running') {
            // Paused / stopped: leave it pending and un-queued; resume re-schedules it.
            $row->update(['scheduled_for' => null]);
            return;
        }

        $creds = $sender->credentials($campaign);
        if (!$creds) {
            $this->finish($campaign, $row, $sender, $row->phone, [
                'ok' => false, 'wa_message_id' => null, 'error_type' => 'auth', 'raw' => 'WA credentials missing', 'retry_after' => null,
                'reason' => $sender->friendly('auth') . ' — WhatsApp credentials are missing for this campaign.',
            ], $row->attempts + 1);
            return;
        }

        // Same number twice in one campaign (duplicate contact rows) -> message it only once.
        $dup = CampaignContact::where('campaign_id', $row->campaign_id)->where('phone', $row->phone)->where('id', '!=', $row->id)
            ->whereIn('status', ['sent', 'delivered', 'read'])->exists();
        if ($dup) {
            $this->finish($campaign, $row, $sender, $row->phone, [
                'ok' => false, 'wa_message_id' => null, 'error_type' => 'duplicate', 'raw' => 'duplicate', 'retry_after' => null,
                'reason' => 'Skipped: this number was already messaged in this campaign (duplicate).',
            ], 0);
            return;
        }

        [$token, $phoneId] = $creds;
        $phone      = preg_replace('/\D+/', '', (string) $row->phone);
        $attempts   = $row->attempts;
        $renumbered = false;

        do {
            $attempts++;
            $r = $sender->send($token, $phoneId, $phone, $campaign->template, $campaign->template_variables ?? []);

            // Bad number format -> re-clean once (drop leading zeros) and retry straight away.
            if (!$r['ok'] && $r['error_type'] === 'bad_number' && !$renumbered) {
                $fixed = ltrim($phone, '0');
                if ($fixed !== '' && $fixed !== $phone) { $renumbered = true; $phone = $fixed; $retryNow = true; continue; }
            }
            $retryNow = false;
        } while ($retryNow);

        Log::info("Campaign {$campaign->id} contact {$row->id}: " . ($r['ok'] ? 'sent' : "failed ({$r['error_type']})"));

        // Temporary Meta/network problem: try again later via the queue (never sleeps a worker).
        if (!$r['ok'] && $r['error_type'] === 'network' && $attempts < self::MAX_SENDS) {
            $backoff = $attempts * 30;
            $row->update(['attempts' => $attempts, 'scheduled_for' => now()->addSeconds($backoff), 'failed_reason' => 'Retrying: ' . mb_substr((string) $r['raw'], 0, 300)]);
            $this->release($backoff);
            return;
        }

        $this->finish($campaign, $row, $sender, $phone, $r, $attempts);
    }

    /** Persist the result, then move the campaign counters and close the campaign when nothing is pending. */
    private function finish(Campaign $campaign, CampaignContact $row, CampaignMessageSender $sender, string $phone, array $r, int $attempts): void
    {
        $sender->record($campaign, $row, $phone, $r, $attempts);

        Campaign::where('id', $campaign->id)->update([
            $r['ok'] ? 'sent' : 'failed' => DB::raw(($r['ok'] ? 'sent' : 'failed') . ' + 1'),
            'pending' => DB::raw('GREATEST(pending - 1, 0)'),
        ]);

        // Done when nothing is pending. Atomic: only the job that observes pending=0 completes it.
        Campaign::where('id', $campaign->id)->where('status', 'running')->where('pending', '<=', 0)
            ->update(['status' => 'completed', 'completed_at' => now()]);
    }

    /** Queue gave up (exception, timeout, tries exhausted): record the reason instead of leaving it pending forever. */
    public function failed(\Throwable $e): void
    {
        Log::error("Campaign contact {$this->campaignContactId} job failed: {$e->getMessage()}");

        $row = CampaignContact::find($this->campaignContactId);
        if (!$row || $row->status !== 'pending') { return; }
        $campaign = Campaign::with('template')->find($row->campaign_id);
        if (!$campaign) { return; }

        $sender = app(CampaignMessageSender::class);
        $this->finish($campaign, $row, $sender, $row->phone, [
            'ok' => false, 'wa_message_id' => null, 'error_type' => 'job_failed', 'raw' => $e->getMessage(), 'retry_after' => null,
            'reason' => 'Sending job failed — ' . mb_substr($e->getMessage(), 0, 300) . ' — resend this number.',
        ], $row->attempts + 1);
    }
}
