<?php

namespace App\Modules\Campaign\Jobs;

use App\Models\Campaign;
use App\Modules\Campaign\Exceptions\CampaignException;
use App\Modules\Campaign\Services\CampaignService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Launches one scheduled campaign on the queue (resolving contacts, inserting rows and debiting the
 * wallet can be heavy for big lists, so the every-minute scheduler only dispatches this).
 */
class LaunchScheduledCampaign implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 1;
    public int $timeout = 600;
    public int $uniqueFor = 900;

    public function __construct(private readonly int $campaignId, private readonly int $companyId) {}

    public function uniqueId(): string { return (string) $this->campaignId; }

    public function handle(CampaignService $campaigns): void
    {
        try {
            $result = $campaigns->launch($this->campaignId, $this->companyId);
            Log::info("Scheduled campaign {$this->campaignId} launched for {$result->totalContacts} contacts.");
        } catch (CampaignException $e) {
            // e.g. no contacts / low wallet / already running — record why instead of retrying forever.
            $this->markFailed($e->getMessage());
        }
    }

    public function failed(\Throwable $e): void
    {
        $this->markFailed($e->getMessage());
    }

    private function markFailed(string $why): void
    {
        Log::error("Scheduled campaign {$this->campaignId} launch failed: {$why}");
        Campaign::where('id', $this->campaignId)->whereIn('status', ['scheduled', 'draft'])->update(['status' => 'failed']);
    }
}
