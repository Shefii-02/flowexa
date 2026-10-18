<?php

namespace App\Modules\Campaign\Jobs;

use App\Models\Campaign;
use App\Models\CampaignContact;
use App\Modules\Campaign\Repositories\Interfaces\CampaignRepositoryInterface;
use App\Modules\Campaign\Services\CampaignMessageSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Campaign scheduler (not the sender). Each run hands the next WINDOW of pending contacts to the
 * queue as one SendCampaignMessage job apiece, each delayed by the campaign's send gap
 * (60 / throttle_per_minute seconds) so messages leave evenly over time. It then re-queues itself
 * for when that window ends. The queue holds at most WINDOW jobs per campaign, so many companies
 * can run big campaigns at once without flooding Redis or any worker.
 */
class ProcessCampaignBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const WINDOW       = 100; // contacts queued per run
    private const STUCK_MINUTES = 15;  // a queued row this overdue lost its job (flush/crash) and is re-queued

    public int $tries   = 3;
    public int $timeout = 120;

    public function __construct(
        private readonly int $campaignId,
        private readonly int $companyId,
    ) {}

    public function handle(CampaignRepositoryInterface $campaignRepository, CampaignMessageSender $sender): void
    {
        // One scheduler per campaign at a time (launch, resume and self-redispatch can each queue one).
        $lock = Cache::lock("campaign-batch:{$this->campaignId}", $this->timeout + 30);
        if (!$lock->get()) {
            Log::info("Campaign {$this->campaignId}: another scheduler run is active — skipped.");
            return;
        }
        try {
            $this->schedule($campaignRepository, $sender);
        } finally {
            $lock->release();
        }
    }

    private function schedule(CampaignRepositoryInterface $campaignRepository, CampaignMessageSender $sender): void
    {
        $campaign = Campaign::with(['template', 'waPhoneNumber', 'company'])->find($this->campaignId);

        if (!$campaign || $campaign->status !== 'running') {
            Log::info("Campaign {$this->campaignId} is not running — scheduler skipped.");
            return;
        }

        if (!$sender->credentials($campaign)) {
            Log::error("Campaign {$this->campaignId}: WA credentials missing.");
            $campaignRepository->updateStatus($campaign, 'failed');
            return;
        }

        // Recover rows whose delayed job vanished, so the campaign can never stall silently.
        CampaignContact::where('campaign_id', $this->campaignId)->where('status', 'pending')
            ->where('scheduled_for', '<', now()->subMinutes(self::STUCK_MINUTES))
            ->update(['scheduled_for' => null]);

        $gap   = 60 / max(1, (int) $campaign->throttle_per_minute); // seconds between two messages
        $limit = self::WINDOW;

        // Daily limit: at most N messages in any rolling 24h (counting ones already queued).
        $windowResetAt = null;
        if ($campaign->daily_limit) {
            $since  = now()->subDay();
            $used   = CampaignContact::where('campaign_id', $this->campaignId)->where('sent_at', '>=', $since)->count()
                    + CampaignContact::where('campaign_id', $this->campaignId)->where('status', 'pending')->whereNotNull('scheduled_for')->count();
            $room   = $campaign->daily_limit - $used;
            if ($room <= 0) {
                $oldest = CampaignContact::where('campaign_id', $this->campaignId)->where('sent_at', '>=', $since)->min('sent_at');
                $windowResetAt = $oldest ? Carbon::parse($oldest)->addDay()->addMinute() : now()->addMinutes(5);
            }
            $limit = max(0, min($limit, $room));
        }

        $rows = $limit > 0 ? $campaignRepository->getUnqueuedPendingContacts($this->campaignId, $limit) : collect();

        // Continue after the last already-queued message so a top-up never overlaps the previous window.
        $lastQueued = CampaignContact::where('campaign_id', $this->campaignId)->where('status', 'pending')->max('scheduled_for');
        $start = $lastQueued ? Carbon::parse($lastQueued)->addSeconds((int) ceil($gap)) : now();
        if ($start->lt(now())) { $start = now(); }

        $last = $start;
        foreach ($rows->values() as $i => $row) {
            $at = $start->copy()->addSeconds((int) floor($i * $gap));
            $row->update(['scheduled_for' => $at]);
            SendCampaignMessage::dispatch($row->id)->onQueue('campaigns')->delay($at);
            $last = $at;
        }

        Log::info("[campaign-scheduler] campaign {$this->campaignId}: queued {$rows->count()} send job(s), gap " . round($gap, 2) . 's, last at ' . $last->toDateTimeString());

        if ($campaignRepository->hasUnqueuedPendingContacts($this->campaignId)) {
            // More left: come back when this window is nearly done (or when the daily window reopens).
            $next = $windowResetAt ?? ($rows->isEmpty() ? now()->addMinute() : $last->copy()->subSeconds((int) min(30, $gap * 10)));
            self::dispatch($this->campaignId, $this->companyId)->onQueue('campaigns')->delay($next->lt(now()) ? now()->addSeconds(5) : $next);
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error("Campaign scheduler {$this->campaignId} permanently failed: {$e->getMessage()}");

        Campaign::where('id', $this->campaignId)->where('status', 'running')->update(['status' => 'failed']);
    }
}
