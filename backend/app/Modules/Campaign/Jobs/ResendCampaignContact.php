<?php

namespace App\Modules\Campaign\Jobs;

use App\Modules\Campaign\Services\CampaignService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

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
        /** template_id / wa_phone_number_id / variables / coupon_code chosen for this retry (empty = the campaign's own) */
        private readonly array $overrides = [],
    ) {}

    public function handle(CampaignService $campaigns): void
    {
        Log::info("[campaign-retry] job started: campaign {$this->campaignId} row {$this->contactRowId} phone {$this->phone}");
        $r = $campaigns->resendContact($this->campaignId, $this->contactRowId, $this->phone, $this->overrides);
        Log::info("[campaign-retry] job finished: campaign {$this->campaignId} row {$this->contactRowId} -> " . ($r['ok'] ? 'sent' : 'failed: ' . ($r['error'] ?? 'row already handled')));
    }

    /** Job crashed: free the row so it can be retried again instead of looking queued forever. */
    public function failed(\Throwable $e): void
    {
        \App\Models\CampaignContact::where('id', $this->contactRowId)->where('status', 'failed')->update([
            'scheduled_for' => null,
            'failed_reason' => 'Retry job failed — ' . mb_substr($e->getMessage(), 0, 300),
        ]);
    }
}
