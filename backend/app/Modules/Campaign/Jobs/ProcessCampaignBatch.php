<?php

namespace App\Modules\Campaign\Jobs;

use App\Models\Campaign;
use App\Modules\Campaign\Support\TemplateMessagePayload;
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

    private const MAX_ATTEMPTS = 3; // sends per contact (first try + auto-fix/backoff retries)

    public int $tries   = 3;
    public int $timeout = 300;

    public function __construct(
        private readonly int $campaignId,
        private readonly int $companyId,
    ) {}

    public function handle(CampaignRepositoryInterface $campaignRepository): void
    {
        // Only ONE batch per campaign at a time. Launch, resend and the 60s self-redispatch can each
        // queue a job; two running together read the same pending rows and message every contact twice
        // (and each then re-dispatches itself, so the duplicates multiply).
        $lock = \Illuminate\Support\Facades\Cache::lock("campaign-batch:{$this->campaignId}", $this->timeout + 30);
        if (!$lock->get()) {
            Log::info("Campaign {$this->campaignId}: another batch is running — job skipped.");
            return;
        }
        try {
            $this->process($campaignRepository);
        } finally {
            $lock->release();
        }
    }

    private function process(CampaignRepositoryInterface $campaignRepository): void
    {
        $campaign = Campaign::with(['template', 'waPhoneNumber'])->find($this->campaignId);

        if (!$campaign || $campaign->status !== 'running') {
            Log::info("Campaign {$this->campaignId} is not running — job skipped.");
            return;
        }

        $credentials = $this->resolveCredentials($campaign);

        if (!$credentials) {
            Log::error("Campaign {$this->campaignId}: WA credentials missing.");
            $campaignRepository->updateStatus($campaign, 'failed');
            return;
        }

        [$token, $phoneId] = $credentials;
        $throttle = $campaign->throttle_per_minute;
        $batchSize= min($throttle, 50); // process up to 50 per tick
        $sent     = 0;
        $failed   = 0;

        // Daily limit: at most N messages in any rolling 24h; the rest waits for the next window.
        $windowResetAt = null;
        if ($campaign->daily_limit) {
            $windowStart = now()->subDay();
            $sentInWindow = \App\Models\CampaignContact::where('campaign_id', $this->campaignId)->where('sent_at', '>=', $windowStart)->count();
            $room = $campaign->daily_limit - $sentInWindow;
            if ($room <= 0) {
                $oldest = \App\Models\CampaignContact::where('campaign_id', $this->campaignId)->where('sent_at', '>=', $windowStart)->min('sent_at');
                $windowResetAt = \Illuminate\Support\Carbon::parse($oldest)->addDay()->addMinute();
            }
            $batchSize = max(0, min($batchSize, $room));
        }

        $contacts = $batchSize > 0 ? $campaignRepository->getPendingContacts($this->campaignId, $batchSize) : collect();

        foreach ($contacts as $cc) {
            // Re-check if campaign was paused mid-batch
            $campaign->refresh();
            if ($campaign->status !== 'running') {
                Log::info("Campaign {$this->campaignId} paused — stopping batch.");
                break;
            }

            // Same number twice in one campaign (duplicate contact rows) -> message it only once.
            // Also re-check this row is still pending: another path may have just sent it.
            $fresh = \App\Models\CampaignContact::find($cc->id);
            if (!$fresh || $fresh->status !== 'pending') { continue; }
            $alreadySent = \App\Models\CampaignContact::where('campaign_id', $this->campaignId)
                ->where('phone', $cc->phone)->where('id', '!=', $cc->id)
                ->whereIn('status', ['sent', 'delivered', 'read'])->exists();
            if ($alreadySent) {
                $campaignRepository->markContactFailed($cc->id, 'Skipped: this number was already messaged in this campaign (duplicate).', 0, 'duplicate');
                $failed++;
                continue;
            }

            // ── Send with automatic diagnosis + recovery (up to MAX_ATTEMPTS tries) ─────────
            //   network / rate limit / 5xx  -> wait, retry
            //   bad number format           -> re-clean (digits only, drop leading zeros), retry once
            //   not on WhatsApp, template or billing problem, auth -> permanent, stop and say why
            $phone       = preg_replace('/\D+/', '', (string) $cc->phone);
            $attempts    = 0;
            $waMessageId = null;
            $errType     = null;
            $rawError    = null;
            $renumbered  = false;

            while ($attempts < self::MAX_ATTEMPTS) {
                $attempts++;
                try {
                    $payload  = $this->buildPayload($phone, $campaign);
                    $response = Http::withToken($token)
                        ->timeout(10)
                        ->post("https://graph.facebook.com/v21.0/{$phoneId}/messages", $payload);
                } catch (\Throwable $e) {
                    // Retry only when the request provably never left; a read timeout is ambiguous
                    // (Meta may have accepted it) and a retry could message the contact twice.
                    $errType  = $e instanceof \Illuminate\Http\Client\ConnectionException
                        ? (preg_match('/cURL error (6|7|35)\b/', $e->getMessage()) ? 'network' : 'timeout')
                        : 'error';
                    $rawError = $e->getMessage();
                    Log::error("Campaign {$this->campaignId} attempt {$attempts} exception for {$phone}: {$rawError}");
                    if ($errType === 'network' && $attempts < self::MAX_ATTEMPTS) { sleep($attempts * 3); continue; }
                    break;
                }

                if ($response->successful()) {
                    $waMessageId = $response->json('messages.0.id') ?? '';
                    $errType = $rawError = null;
                    break;
                }

                $rawError = $response->json('error.message') ?? 'Unknown error';
                if ($metaCode = $response->json('error.code')) { $rawError = "(code {$metaCode}) {$rawError}"; }
                // Meta's `error_data.details` names the exact parameter/component that was wrong
                // (e.g. "header: Format mismatch, expected IMAGE, received UNKNOWN").
                if ($details = $response->json('error.error_data.details')) {
                    $rawError .= " — {$details}";
                }
                $errType = $this->classifyFailure($response->status(), (int) $response->json('error.code'), $rawError);
                Log::warning("Campaign {$this->campaignId} attempt {$attempts} failed for {$phone} ({$errType}): {$rawError}");

                if ($errType === 'network' && $attempts < self::MAX_ATTEMPTS) { sleep($attempts * 3); continue; }

                if ($errType === 'bad_number' && !$renumbered) {
                    $fixed = ltrim($phone, '0');
                    if ($fixed !== '' && $fixed !== $phone) { $renumbered = true; $phone = $fixed; continue; }
                }
                break; // permanent, or out of tries
            }

            if ($waMessageId !== null) {
                if ($phone !== $cc->phone) {
                    \App\Models\CampaignContact::where('id', $cc->id)->update(['phone' => $phone]); // keep the auto-corrected number
                }
                $campaignRepository->markContactSent($cc->id, $waMessageId, $attempts);
                \App\Models\MessageLog::recordCloudOutbound($campaign->company_id, $phone, 'template',
                    ['body' => '[Template] ' . ($campaign->template?->name ?? '') . ' — campaign: ' . $campaign->name, 'template' => $campaign->template?->name],
                    'campaign', $waMessageId, null, (string) $campaign->id, $campaign->name, strtolower((string) $campaign->template?->category));
                // Show the campaign message in the shared inbox; delivered / read / failed webhooks then update it by wa_message_id.
                try {
                    $conv = \App\Models\WaConversation::firstOrCreate(['company_id' => $campaign->company_id, 'phone' => $phone], ['status' => 'open']);
                    $conv->update(['last_message_at' => now()]);
                    \App\Models\WaMessage::create([
                        'conversation_id' => $conv->id, 'company_id' => $campaign->company_id,
                        'direction' => 'outbound', 'sender_type' => 'system', 'wa_message_id' => $waMessageId ?: null,
                        'type' => 'template', 'content' => ['body' => '[Template] ' . ($campaign->template?->name ?? '') . ' — campaign: ' . $campaign->name],
                        'status' => 'sent', 'status_updated_at' => now(),
                    ]);
                } catch (\Throwable $e) {
                    Log::warning("Campaign {$this->campaignId}: inbox log failed for {$phone}: {$e->getMessage()}");
                }
                $sent++;
            } else {
                // A capped number fails the same way if retried straight away — hold retries for 24h
                $retryAfter = $errType === 'frequency_capped' ? now()->addHours(24) : null;
                $campaignRepository->markContactFailed(
                    $cc->id,
                    $retryAfter
                        ? 'WhatsApp is limiting marketing messages to this number. Retry is available from ' . $retryAfter->format('d M, h:i A') . '.'
                        : $this->friendlyFailure($errType, $attempts) . ' — ' . mb_substr((string) $rawError, 0, 400),
                    $attempts,
                    $errType ?? 'error',
                    $retryAfter,
                );
                Log::warning("Campaign {$this->campaignId} gave up on {$phone} after {$attempts} try(s): {$errType}");
                \App\Models\MessageLog::recordCloudOutbound($campaign->company_id, $phone, 'template',
                    ['body' => '[Template] ' . ($campaign->template?->name ?? '') . ' — campaign: ' . $campaign->name, 'template' => $campaign->template?->name],
                    'campaign', null, mb_substr((string) $rawError, 0, 400) ?: 'Send failed', (string) $campaign->id, $campaign->name, strtolower((string) $campaign->template?->category), $retryAfter);
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
                    ->delay($windowResetAt ?? now()->addSeconds(60)); // next batch after 1 min (or when the daily window reopens)
            }
        }
    }

    /** What kind of failure is this Meta response? Decides whether a retry / auto-fix can help. */
    private function classifyFailure(int $status, int $code, string $message): string
    {
        return match (true) {
            $code === 131049                                             => 'frequency_capped', // per-user marketing cap
            $code === 131026                                             => 'not_on_whatsapp',   // message undeliverable
            $code === 131030                                             => 'not_allowed',       // recipient not in allowed list
            in_array($code, [131042, 131043], true)                      => 'billing',
            $code === 190 || $status === 401                             => 'auth',
            in_array($code, [132000, 132001, 132005, 132007, 132012, 132015, 132016], true) => 'template',
            in_array($code, [1, 2, 4, 17, 80007, 130429, 131000, 131016, 131056], true)
                || $status === 429 || $status >= 500                     => 'network',
            $code === 100 && preg_match('/phone|recipient|\bto\b/i', $message) => 'bad_number',
            default                                                      => 'error',
        };
    }

    private function friendlyFailure(?string $type, int $attempts): string
    {
        $tries = $attempts . ' ' . ($attempts === 1 ? 'try' : 'tries');
        return match ($type) {
            'not_on_whatsapp' => "Number is not on WhatsApp ({$tries})",
            'not_allowed'     => "Recipient not in the allowed list for this number ({$tries})",
            'bad_number'      => "Meta rejected this number — check country code and digits, edit and resend ({$tries})",
            'template'        => "Template / parameter problem — fix the template, then resend ({$tries})",
            'billing'         => "WhatsApp billing/payment issue on the Meta account ({$tries})",
            'auth'            => "Access token invalid or expired — reconnect WhatsApp ({$tries})",
            'network'         => "Meta/network problem, still failing after {$tries} — resend later",
            'timeout'         => "No reply from Meta in time ({$tries}) — the message may still have been delivered. Check before resending",
            default           => "Send failed after {$tries}",
        };
    }

    /**
     * A campaign created with a specific WA Cloud number (multi-number companies)
     * sends from that number's own credentials; a campaign with none set (or an
     * older company still on the single-number setup) falls back to the company's
     * legacy wa_phone_id/wa_access_token, exactly as this job always used to behave.
     *
     * @return array{0:string,1:string}|null [token, phoneId], or null if neither source has credentials
     */
    private function resolveCredentials(Campaign $campaign): ?array
    {
        $number = $campaign->waPhoneNumber;
        if ($number && $number->is_active && $number->access_token && $number->phone_number_id) {
            return [$number->decrypted_token, $number->phone_number_id];
        }

        $company = $campaign->company;
        if ($company->wa_phone_id && $company->wa_access_token) {
            return [decrypt($company->wa_access_token), $company->wa_phone_id];
        }

        return null;
    }

    // ─── Build WhatsApp API payload ───────────────────────────────────────────
    private function buildPayload(string $phone, Campaign $campaign): array
    {
        $template = $campaign->template;

        $format = strtoupper((string) $template->header_format);
        if (in_array($format, ['IMAGE', 'VIDEO', 'DOCUMENT'], true) && !$template->header_sample_url) {
            Log::warning("Campaign {$this->campaignId}: template '{$template->name}' has a {$format} header but no media URL.");
        }

        return TemplateMessagePayload::build($phone, $template, $campaign->template_variables ?? []);
    }

    public function failed(\Throwable $e): void
    {
        Log::error("Campaign job {$this->campaignId} permanently failed: {$e->getMessage()}");

        Campaign::where('id', $this->campaignId)->update(['status' => 'failed']);
    }
}
