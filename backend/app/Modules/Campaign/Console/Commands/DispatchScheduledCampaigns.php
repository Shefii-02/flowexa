<?php

namespace App\Modules\Campaign\Console\Commands;

use App\Models\Campaign;
use App\Modules\Campaign\Jobs\LaunchScheduledCampaign;
use Illuminate\Console\Command;

/**
 * Queues a launch for every campaign whose scheduled send time has arrived. Runs every minute from
 * the scheduler. LaunchScheduledCampaign then calls CampaignService::launch(), which inserts the
 * pending rows, debits the wallet and starts ProcessCampaignBatch -> one delayed job per contact.
 */
class DispatchScheduledCampaigns extends Command
{
    protected $signature   = 'wa-cloud:dispatch-scheduled-campaigns';
    // Old name kept so an existing server cron / supervisor entry keeps working.
    protected $aliases     = ['campaigns:dispatch-scheduled'];
    protected $description = 'WA Cloud (Meta API): launch campaigns whose scheduled_at time has passed';

    public function handle(): int
    {
        $due = Campaign::query()
            // Never 'running': a campaign already launched by hand keeps its past scheduled_at and
            // must not be launched again.
            ->whereIn('status', ['scheduled', 'draft'])
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')
            ->limit(50)
            ->get(['id', 'name', 'company_id']);
        if ($due->isEmpty()) {
            return self::SUCCESS;
        }

        // Heavy work (contact resolve, row insert, wallet debit) runs on the queue; this tick only dispatches.
        foreach ($due as $campaign) {
            LaunchScheduledCampaign::dispatch($campaign->id, $campaign->company_id)->onQueue('campaigns');
            $this->info("Queued launch of #{$campaign->id}");
        }

        return self::SUCCESS;
    }
}
