<?php

namespace App\Modules\Campaign\Console\Commands;

use App\Models\Campaign;
use App\Models\CampaignContact;
use App\Modules\Campaign\Services\CampaignService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Meta error 131049 (per-user marketing cap) is temporary. Once a number's retry_after time has
 * passed, re-queue it ONCE automatically. A number that was already retried is left for a manual retry.
 */
class AutoRetryCappedContacts extends Command
{
    protected $signature   = 'wa-cloud:auto-retry-capped';
    protected $description = 'WA Cloud (Meta API): auto-retry marketing-capped failures whose wait time has passed (once each)';

    public function handle(CampaignService $campaigns): int
    {
        $due = CampaignContact::where('status', 'failed')
            ->where('error_type', 'frequency_capped')
            ->whereNull('resent_at')
            ->whereNotNull('retry_after')
            ->where('retry_after', '<=', now())
            ->get(['id', 'campaign_id'])
            ->groupBy('campaign_id');

        foreach ($due as $campaignId => $rows) {
            $campaign = Campaign::find($campaignId);
            if (!$campaign || $campaign->status === 'running') { continue; } // picked up on a later run
            if (!$campaign->auto_retry) { Log::info("[auto-retry] campaign {$campaign->id}: auto-retry is stopped, skipped."); continue; }
            try {
                $r = $campaigns->resendFailed($campaign->id, $campaign->company_id, $rows->map(fn ($c) => ['id' => $c->id])->all());
                Log::info("Auto-retry queued {$r['queued']} capped number(s) for campaign {$campaign->id}.");
            } catch (\Throwable $e) {
                Log::warning("Auto-retry of capped numbers failed for campaign {$campaign->id}: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
