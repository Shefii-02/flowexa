<?php

namespace App\Modules\Campaign\Services;

use App\Models\Campaign;
use App\Models\CampaignContact;
use App\Models\MessageLog;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Models\WaTemplate;
use App\Modules\Campaign\Support\TemplateMessagePayload;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The one place that talks to Meta for a campaign-style template message. Used by the queued
 * per-contact job, "resend failed" and the test-send endpoint, so a failure is classified,
 * worded and logged the same way everywhere.
 */
class CampaignMessageSender
{
    /**
     * One attempt, no sleeping/retrying here (the queue job decides when to try again).
     *
     * @return array{ok:bool, wa_message_id:?string, error_type:?string, raw:?string, reason:?string, retry_after:?\Carbon\Carbon}
     */
    public function send(string $token, string $phoneId, string $phone, WaTemplate $template, array $variables, ?string $coupon = null): array
    {
        try {
            $response = Http::withToken($token)->timeout(15)
                ->post("https://graph.facebook.com/v21.0/{$phoneId}/messages",
                    TemplateMessagePayload::build($phone, $template, $variables, $coupon));
        } catch (\Throwable $e) {
            // Only a request that provably never left is retryable; a read timeout is ambiguous
            // (Meta may have accepted it) and retrying could message the person twice.
            $type = $e instanceof ConnectionException && preg_match('/cURL error (6|7|35)\b/', $e->getMessage()) ? 'network'
                  : ($e instanceof ConnectionException ? 'timeout' : 'error');
            return $this->failure($type, $e->getMessage());
        }

        if ($response->successful()) {
            return ['ok' => true, 'wa_message_id' => (string) ($response->json('messages.0.id') ?? ''), 'error_type' => null, 'raw' => null, 'reason' => null, 'retry_after' => null, 'meta' => $response->json()];
        }

        $code = (int) $response->json('error.code');
        $raw  = $response->json('error.message') ?? 'Unknown error';
        if ($code) { $raw = "(code {$code}) {$raw}"; }
        // `error_data.details` names the exact parameter/component that was wrong.
        if ($details = $response->json('error.error_data.details')) { $raw .= " — {$details}"; }

        return $this->failure($this->classify($response->status(), $code, $raw), $raw) + ['http' => $response->status(), 'meta' => $response->json()];
    }

    /** @return array{ok:false, wa_message_id:null, error_type:string, raw:string, reason:string, retry_after:?\Carbon\Carbon} */
    private function failure(string $type, string $raw): array
    {
        // A capped number fails the same way if retried straight away — hold retries for 2h.
        $retryAfter = $type === 'frequency_capped' ? now()->addHours(2) : null;
        $reason = $retryAfter
            ? 'WhatsApp is limiting marketing messages to this number. Retry is available from ' . $retryAfter->format('d M, h:i A') . '.'
            : $this->friendly($type) . ' — ' . mb_substr($raw, 0, 400);

        return ['ok' => false, 'wa_message_id' => null, 'error_type' => $type, 'raw' => $raw, 'reason' => $reason, 'retry_after' => $retryAfter];
    }

    /** What kind of failure is this Meta response? Decides whether a retry / auto-fix can help. */
    public function classify(int $status, int $code, string $message): string
    {
        return match (true) {
            $code === 131049                                             => 'frequency_capped',
            $code === 131026                                             => 'not_on_whatsapp',
            $code === 131030                                             => 'not_allowed',
            in_array($code, [131042, 131043], true)                      => 'billing',
            $code === 190 || $status === 401                             => 'auth',
            in_array($code, [132000, 132001, 132005, 132007, 132012, 132015, 132016], true) => 'template',
            in_array($code, [1, 2, 4, 17, 80007, 130429, 131000, 131016, 131056], true)
                || $status === 429 || $status >= 500                     => 'network',
            $code === 100 && preg_match('/phone|recipient|\bto\b/i', $message) => 'bad_number',
            default                                                      => 'error',
        };
    }

    public function friendly(?string $type): string
    {
        return match ($type) {
            'not_on_whatsapp' => 'Number is not on WhatsApp',
            'not_allowed'     => 'Recipient not in the allowed list for this number',
            'bad_number'      => 'Meta rejected this number — check country code and digits, edit and resend',
            'template'        => 'Template / parameter problem — fix the template, then resend',
            'billing'         => 'WhatsApp billing/payment issue on the Meta account',
            'auth'            => 'Access token invalid or expired — reconnect WhatsApp',
            'network'         => 'Meta/network problem — resend later',
            'timeout'         => 'No reply from Meta in time — the message may still have been delivered. Check before resending',
            default           => 'Send failed',
        };
    }

    /** [token, phoneId] for a campaign: its own number, else the company's legacy single-number setup. */
    public function credentials(Campaign $campaign): ?array
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

    /**
     * Record the outcome of one campaign contact: its campaign_contacts row, its single message-log
     * row (updated in place on a retry, never duplicated) and, on success, the shared inbox.
     */
    public function record(Campaign $campaign, CampaignContact $row, string $phone, array $r, int $attempts, bool $isResend = false): void
    {
        $body = ['body' => '[Template] ' . ($campaign->template?->name ?? '') . ' — campaign: ' . $campaign->name . ($isResend ? ' (resend)' : ''), 'template' => $campaign->template?->name];
        $category = strtolower((string) $campaign->template?->category);
        $common = ['phone' => $phone, 'attempts' => $attempts, 'scheduled_for' => null] + ($isResend ? ['resent_at' => now()] : []);

        if ($r['ok']) {
            $row->update($common + [
                'status' => 'sent', 'wa_message_id' => $r['wa_message_id'] ?: null, 'sent_at' => now(),
                'failed_reason' => null, 'error_type' => null, 'retry_after' => null,
            ]);
            MessageLog::recordCloudOutbound($campaign->company_id, $phone, 'template', $body, 'campaign', $r['wa_message_id'], null,
                (string) $campaign->id, $campaign->name, $category, null, $row->id);
            $this->logToInbox($campaign, $phone, $r['wa_message_id']);
            return;
        }

        $row->update($common + [
            'status' => 'failed', 'failed_reason' => $r['reason'], 'error_type' => $r['error_type'], 'retry_after' => $r['retry_after'],
        ]);
        MessageLog::recordCloudOutbound($campaign->company_id, $phone, 'template', $body, 'campaign', null,
            mb_substr((string) ($r['reason'] ?: $r['raw']), 0, 500) ?: 'Send failed',
            (string) $campaign->id, $campaign->name, $category, $r['retry_after'], $row->id);
    }

    private function logToInbox(Campaign $campaign, string $phone, ?string $waMessageId): void
    {
        try {
            $conv = WaConversation::firstOrCreate(['company_id' => $campaign->company_id, 'phone' => $phone], ['status' => 'open']);
            $conv->update(['last_message_at' => now()]);
            WaMessage::create([
                'conversation_id' => $conv->id, 'company_id' => $campaign->company_id,
                'direction' => 'outbound', 'sender_type' => 'system', 'wa_message_id' => $waMessageId ?: null,
                'type' => 'template', 'content' => ['body' => '[Template] ' . ($campaign->template?->name ?? '') . ' — campaign: ' . $campaign->name],
                'status' => 'sent', 'status_updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning("Campaign {$campaign->id}: inbox log failed for {$phone}: {$e->getMessage()}");
        }
    }
}
