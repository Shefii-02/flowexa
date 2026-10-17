<?php

namespace App\Modules\Campaign\Jobs;

use App\Modules\Campaign\Services\CampaignService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Resends ONE failed campaign contact straight to the Meta API (used when many are selected at once). */
class ResendCampaignContact implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 1; // never retried automatically — a second try could message the person twice
    public int $timeout = 60;

    public function __construct(
        private readonly int $campaignId,
        private readonly int $contactRowId,
        private readonly string $phone,
    ) {}

    public function handle(CampaignService $campaigns): void
    {
        $campaigns->resendContact($this->campaignId, $this->contactRowId, $this->phone);
    }
}
