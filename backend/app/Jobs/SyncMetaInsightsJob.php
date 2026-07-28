<?php
namespace App\Jobs;

use App\Models\MetaCampaign;
use App\Modules\MetaAds\Services\MetaAdsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class SyncMetaInsightsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries   = 3;
    public int $timeout = 120;

    public function handle(MetaAdsService $svc): void
    {
        $yesterday = now()->subDay()->toDateString();
        $today     = now()->toDateString();

        // Sync all active campaigns (not deleted/archived)
        MetaCampaign::whereNotNull('meta_campaign_id')
            ->whereIn('status', ['ACTIVE', 'PAUSED'])
            ->with('adAccount')
            ->chunkById(50, function ($campaigns) use ($svc, $yesterday, $today) {
                foreach ($campaigns as $campaign) {
                    try {
                        $svc->syncInsights($campaign, $yesterday, $today);
                    } catch (\Exception $e) {
                        \Illuminate\Support\Facades\Log::error("Meta insights sync failed for campaign {$campaign->id}: " . $e->getMessage());
                    }
                    sleep(1); // rate limit courtesy
                }
            });
    }
}
