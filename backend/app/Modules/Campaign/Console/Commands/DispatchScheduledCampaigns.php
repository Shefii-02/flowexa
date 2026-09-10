<?php

namespace App\Modules\Campaign\Console\Commands;

use App\Models\Campaign;
use App\Modules\Campaign\Services\CampaignService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Launches campaigns whose scheduled send time has arrived. Runs every minute
 * from the scheduler. Each launch reuses CampaignService::launch(), which inserts
 * the pending contact rows, debits the wallet (wallet-mode companies) and
 * dispatches ProcessCampaignBatch onto the "campaigns" queue.
 */
class DispatchScheduledCampaigns extends Command
{
    protected $signature   = 'campaigns:dispatch-scheduled';
    protected $description  = 'Launch campaigns whose scheduled_at time has passed';

    public function handle(CampaignService $campaigns): int
    {
        $due = Campaign::query()
            ->whereIn('status', ['scheduled', 'draft'])
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')
            ->limit(50)
            ->get(['id', 'name', 'company_id']);

        if ($due->isEmpty()) {
            return self::SUCCESS;
        }

        foreach ($due as $campaign) {
            try {
                $result = $campaigns->launch($campaign->id, $campaign->company_id);

                Log::info("Scheduled campaign {$campaign->id} '{$campaign->name}' auto-launched for {$result->totalContacts} contacts.");
                $this->info("Launched #{$campaign->id} — {$result->totalContacts} contacts");
            } catch (\Throwable $e) {
                Log::error("Scheduled campaign {$campaign->id} auto-launch failed: {$e->getMessage()}");
                $this->error("Campaign #{$campaign->id} failed: {$e->getMessage()}");

                Campaign::where('id', $campaign->id)
                    ->whereIn('status', ['scheduled', 'draft'])
                    ->update(['status' => 'failed']);
            }
        }

        return self::SUCCESS;
    }
}
