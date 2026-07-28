<?php

namespace App\Modules\Campaign\Jobs;

use App\Models\Campaign;
use App\Modules\Campaign\Repositories\Interfaces\CampaignRepositoryInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProcessCampaignBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 300;

    public function __construct(
        private readonly int $campaignId,
        private readonly int $companyId,
    ) {}

    public function handle(CampaignRepositoryInterface $campaignRepository): void
    {
        $campaign = Campaign::with('template')->find($this->campaignId);

        if (!$campaign || $campaign->status !== 'running') {
            Log::info("Campaign {$this->campaignId} is not running — job skipped.");
            return;
        }

        $company = $campaign->company;

        if (!$company->wa_phone_id || !$company->wa_access_token) {
            Log::error("Campaign {$this->campaignId}: WA credentials missing.");
            $campaignRepository->updateStatus($campaign, 'failed');
            return;
        }

        $token    = decrypt($company->wa_access_token);
        $phoneId  = $company->wa_phone_id;
        $throttle = $campaign->throttle_per_minute;
        $batchSize= min($throttle, 50); // process up to 50 per tick
        $sent     = 0;
        $failed   = 0;

        $contacts = $campaignRepository->getPendingContacts($this->campaignId, $batchSize);

        foreach ($contacts as $cc) {
            // Re-check if campaign was paused mid-batch
            $campaign->refresh();
            if ($campaign->status !== 'running') {
                Log::info("Campaign {$this->campaignId} paused — stopping batch.");
                break;
            }

            try {
                $payload  = $this->buildPayload($cc->phone, $campaign);
                $response = Http::withToken($token)
                    ->timeout(10)
                    ->post("https://graph.facebook.com/v21.0/{$phoneId}/messages", $payload);

                if ($response->successful()) {
                    $waMessageId = $response->json('messages.0.id');
                    $campaignRepository->markContactSent($cc->id, $waMessageId ?? '');
                    $sent++;
                } else {
                    $error = $response->json('error.message') ?? 'Unknown error';
                    $campaignRepository->markContactFailed($cc->id, $error);
                    Log::warning("Campaign {$this->campaignId} send failed for {$cc->phone}: {$error}");
                    $failed++;
                }
            } catch (\Exception $e) {
                $campaignRepository->markContactFailed($cc->id, $e->getMessage());
                Log::error("Campaign {$this->campaignId} exception for {$cc->phone}: {$e->getMessage()}");
                $failed++;
            }

            // Throttle: sleep to respect per-minute limit
            usleep((int) ((60 / $throttle) * 1_000_000));
        }

        // Update stats
        $campaignRepository->updateStats($this->campaignId, [
            'sent'    => \DB::raw("sent + {$sent}"),
            'failed'  => \DB::raw("failed + {$failed}"),
            'pending' => \DB::raw("pending - " . ($sent + $failed)),
        ]);

        // Check if all done
        $remaining = Campaign::find($this->campaignId)?->pending ?? 0;

        if ($remaining <= 0) {
            $campaignRepository->updateStats($this->campaignId, [
                'status'       => 'completed',
                'pending'      => 0,
                'completed_at' => now(),
            ]);
            Log::info("Campaign {$this->campaignId} completed.");
        } else {
            // Re-dispatch for next batch if still running
            $campaign->refresh();
            if ($campaign->status === 'running') {
                self::dispatch($this->campaignId, $this->companyId)
                    ->onQueue('campaigns')
                    ->delay(now()->addSeconds(60)); // next batch after 1 min
            }
        }
    }

    // ─── Build WhatsApp API payload ───────────────────────────────────────────
    private function buildPayload(string $phone, Campaign $campaign): array
    {
        $template  = $campaign->template;
        $variables = $campaign->template_variables ?? [];

        $components = [];

        // Body parameters
        if (!empty($variables)) {
            $components[] = [
                'type'       => 'body',
                'parameters' => collect($variables)->map(fn($v) => [
                    'type' => 'text',
                    'text' => (string) $v,
                ])->values()->all(),
            ];
        }

        return [
            'messaging_product' => 'whatsapp',
            'to'                => $phone,
            'type'              => 'template',
            'template'          => [
                'name'       => $template->name,
                'language'   => ['code' => $template->language],
                'components' => $components,
            ],
        ];
    }

    public function failed(\Throwable $e): void
    {
        Log::error("Campaign job {$this->campaignId} permanently failed: {$e->getMessage()}");

        Campaign::where('id', $this->campaignId)->update(['status' => 'failed']);
    }
}
