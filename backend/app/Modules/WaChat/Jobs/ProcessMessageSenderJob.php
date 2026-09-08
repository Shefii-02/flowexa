<?php

namespace App\Modules\WaChat\Jobs;

use App\Models\Company;
use App\Modules\WaChat\Models\MessageSenderJob;
use App\Modules\WaChat\Models\WahaMessageLog;
use App\Modules\WaChat\Services\OpenWaMessageService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\Response;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;

class ProcessMessageSenderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    // Raised from 1: WithoutOverlapping below releases (re-queues) a job that loses the race for
    // the lock, and a release counts as an attempt. With tries=1 a single overlap would exhaust
    // it and the job would be marked failed without ever actually running. handle() itself never
    // lets a send failure escape as an exception (each recipient's try/catch swallows it), so
    // this headroom exists purely to absorb lock-wait releases, not real retries.
    public int $tries   = 100;
    public int $timeout = 3600;

    public function __construct(public int $jobId)
    {
        // Route to the dedicated `campaigns` Horizon supervisor. The `default` supervisor runs a
        // 90s worker timeout (config/horizon.php) — any campaign whose per-recipient delay loop
        // runs longer than that (a handful of recipients is enough) gets SIGKILLed mid-send and
        // retried, so recipients see duplicates and the job never reports done. `campaigns` is
        // provisioned for exactly this long-running work.
        $this->onQueue('campaigns');
    }

    // Enforces strict one-campaign-at-a-time processing: if several campaigns are launched or
    // become due around the same moment, only one ProcessMessageSenderJob actually runs — any
    // other loses the lock and is released back onto the queue to retry shortly after, so the
    // next campaign only starts once the current one finishes rather than running concurrently.
    // Global key (not per-company) — mirrors the shared gateway underneath, which shouldn't be
    // hit by two campaigns' worth of send calls at once regardless of whose they are.
    public function middleware(): array
    {
        return [(new WithoutOverlapping('message-sender-queue'))->releaseAfter(15)->expireAfter($this->timeout + 60)];
    }

    public function handle(): void
    {
        Log::info("ProcessMessageSenderJob #{$this->jobId}: picked up by queue worker");

        $job = MessageSenderJob::find($this->jobId);
        // status is a strict DB enum (pending/running/paused/stopped/scheduled/done) — 'completed'
        // and 'failed' are not members of it, so this only ever matches 'stopped' in practice.
        if (!$job || in_array($job->status, ['stopped', 'done'])) {
            Log::info("ProcessMessageSenderJob #{$this->jobId}: skipped", [
                'found'  => (bool) $job,
                'status' => $job?->status,
            ]);
            return;
        }

        $job->update(['status' => 'running', 'started_at' => $job->started_at ?? now()]);

        $company    = $job->company;
        $apiKey     = $company?->wa_chat_token ?? '';
        $wa         = new OpenWaMessageService();
        $payload    = $job->message_payload ?? [];
        $recipients = $this->dedupeRecipients($payload['recipients'] ?? []);
        $delayMs    = max((int)($job->delay_ms ?? 1000), 500);
        $msgType    = $payload['type'] ?? 'text';

        $logMessageType = $this->resolveLogMessageType($msgType, $payload['blocks'] ?? []);

        Log::info("ProcessMessageSenderJob #{$job->id}: starting", [
            'company_id'      => $job->company_id,
            'session_id'      => $job->session_id,
            'campaign_name'   => $job->campaign_name,
            'msg_type'        => $msgType,
            'recipient_count' => count($recipients),
            'delay_ms'        => $delayMs,
        ]);

        foreach ($recipients as $index => $recipient) {
            $job->refresh();
            if (in_array($job->status, ['stopped', 'paused'])) {
                Log::info("ProcessMessageSenderJob #{$job->id}: halted (status={$job->status}) at recipient {$index}/" . count($recipients));
                break;
            }

            $phone   = $recipient['phone'] ?? '';
            $name    = $recipient['name']  ?? '';
            $chatId  = str_contains($phone, '@') ? $phone : $phone . '@c.us';

            Log::info("ProcessMessageSenderJob #{$job->id}: sending to recipient " . ($index + 1) . '/' . count($recipients), [
                'name'    => $name,
                'phone'   => $phone,
                'chat_id' => $chatId,
            ]);

            $logBase = [
                'company_id'      => $job->company_id,
                'job_id'          => $job->id,
                'campaign_name'   => $job->campaign_name,
                'session_id'      => $job->session_id,
                'recipient_name'  => $name,
                'recipient_phone' => $phone,
                // recipient_type is a strict DB enum matching message_sender_jobs.type's values
                // (personal/group/csv/label/chat/from-chat/campaign) — it is NOT a free-form
                // "group vs contact" flag.
                'recipient_type'  => $job->type,
                'message_type'    => $logMessageType,
                'status'          => 'pending',
            ];

            try {
                $res = match ($msgType) {
                    'media' => $this->sendMediaBlocks($wa, $job->session_id, $apiKey, $chatId, $payload['blocks'] ?? [], $recipient, $job->unique_signature ?? false, $company),
                    'poll' => $wa->sendPoll($job->session_id, $apiKey, $chatId, $payload['question'] ?? '', $payload['options'] ?? []),
                    'location' => $wa->sendLocation($job->session_id, $apiKey, $chatId, (float)($payload['lat'] ?? 0), (float)($payload['lng'] ?? 0), $payload['name'] ?? null, $payload['address'] ?? null),
                    'contact' => $wa->sendContact($job->session_id, $apiKey, $chatId, $payload['contactName'] ?? '', $payload['contactNumber'] ?? ''),
                    'audio' => $wa->sendMedia($job->session_id, $apiKey, $chatId, 'audio', ['url' => $payload['url'] ?? '']),
                    default => $this->sendText($wa, $job->session_id, $apiKey, $chatId, $payload['text'] ?? '', $recipient, $job->unique_signature ?? false, $company),
                };

                if ($res->successful()) {
                    Log::info("ProcessMessageSenderJob #{$job->id}: sent to {$chatId}", ['status' => $res->status()]);
                    WahaMessageLog::create(array_merge($logBase, [
                        'status'          => 'sent',
                        'waha_message_id' => $res->json('messageId') ?? null,
                        'sent_at'         => now(),
                    ]));
                    $job->increment('sent');
                } else {
                    Log::warning("ProcessMessageSenderJob #{$job->id}: failed to {$chatId}", [
                        'status' => $res->status(),
                        'body'   => $res->body(),
                    ]);
                    WahaMessageLog::create(array_merge($logBase, [
                        'status'        => 'failed',
                        'error_message' => $res->body(),
                    ]));
                    $job->increment('failed');
                }
            } catch (\Exception $e) {
                Log::error("ProcessMessageSenderJob #{$job->id}: exception sending to {$chatId}: " . $e->getMessage());
                WahaMessageLog::create(array_merge($logBase, [
                    'status'        => 'failed',
                    'error_message' => $e->getMessage(),
                ]));
                $job->increment('failed');
            }

            if ($index < count($recipients) - 1) {
                usleep($delayMs * 1000);
            }
        }

        $job->refresh();
        if ($job->status === 'running') {
            $job->update(['status' => 'done', 'completed_at' => now()]);
        }

        Log::info("ProcessMessageSenderJob #{$job->id}: finished", [
            'final_status' => $job->status,
            'sent'         => $job->sent,
            'failed'       => $job->failed,
        ]);
    }

    // Backend safety net: the frontend already dedupes by normalized phone before submitting, but a
    // campaign duplicated/restored through an older stored payload, or any other future producer of
    // message_payload, must not be able to double-send just because two recipient rows carry the
    // same number under different casing/spacing. A '@lid'/'@c.us' chat id is an opaque identifier,
    // not a real number — those compare by exact id instead of extracted digits.
    private function dedupeRecipients(array $recipients): array
    {
        $seen = [];
        $out  = [];
        foreach ($recipients as $recipient) {
            $phone = (string) ($recipient['phone'] ?? '');
            $key   = str_contains($phone, '@') ? strtolower($phone) : preg_replace('/\D/', '', $phone);
            if ($key === '' || isset($seen[$key])) continue;
            $seen[$key] = true;
            $out[] = $recipient;
        }
        return $out;
    }

    private function sendText(
        OpenWaMessageService $wa, string $sessionId, string $apiKey, string $chatId,
        string $text, array $recipient, bool $uniqueSig, ?Company $company,
    ): Response {
        $message = $this->personalizeMessage($text, $recipient, $company);
        if ($uniqueSig) $message .= $this->buildSignature($recipient['phone'] ?? '');
        return $wa->sendText($sessionId, $apiKey, $chatId, $message);
    }

    // Sends every block in order and reports success only if the LAST one succeeds — the trailing
    // block is what a recipient actually sees at the end of the sequence, so that is the
    // meaningful pass/fail signal for this recipient's row in the log.
    private function sendMediaBlocks(
        OpenWaMessageService $wa, string $sessionId, string $apiKey, string $chatId,
        array $blocks, array $recipient, bool $uniqueSig, ?Company $company,
    ): Response {
        $last = null;

        foreach ($blocks as $i => $block) {
            $type = $block['type'] ?? 'text';

            if ($type === 'text') {
                $text = $this->personalizeMessage($block['text'] ?? '', $recipient, $company);
                if ($uniqueSig && $i === 0) $text .= $this->buildSignature($recipient['phone'] ?? '');
                $last = $wa->sendText($sessionId, $apiKey, $chatId, $text);
            } else {
                $url = $block['mediaUrl'] ?? $block['url'] ?? '';
                if (!$url) continue;

                $mediaPayload = ['url' => $url];
                if (!empty($block['caption'])) {
                    $mediaPayload['caption'] = $this->personalizeMessage($block['caption'], $recipient, $company);
                }
                if (!empty($block['filename'])) {
                    $mediaPayload['filename'] = $block['filename'];
                }
                $last = $wa->sendMedia($sessionId, $apiKey, $chatId, $type, $mediaPayload);
            }

            if ($i < count($blocks) - 1) {
                usleep(600_000);
            }
        }

        if (!$last) {
            // No block actually produced a request (e.g. every media block was missing a url) —
            // let the caller's catch log this recipient as failed with a clear reason, same as
            // any other send exception, rather than fabricating a fake HTTP response.
            throw new \RuntimeException('No media block had a sendable url or text.');
        }

        return $last;
    }

    // waha_message_logs.message_type is a strict DB enum of concrete content types
    // (text/image/video/audio/document/template/poll/location/contact) — it has no 'media'
    // member, since 'media' is only this app's own wrapper meaning "a sequence of blocks", not a
    // real content type. A 'media' payload logs as the type of its last block (the one that
    // actually determines whether the recipient received anything), falling back to 'text'.
    private function resolveLogMessageType(string $msgType, array $blocks): string
    {
        static $valid = ['text', 'image', 'video', 'audio', 'document', 'template', 'poll', 'location', 'contact'];

        if ($msgType === 'media') {
            $lastBlock = end($blocks) ?: [];
            $lastType = $lastBlock['type'] ?? 'text';
            return in_array($lastType, $valid, true) ? $lastType : 'text';
        }

        return in_array($msgType, $valid, true) ? $msgType : 'text';
    }

    // Every placeholder always gets substituted with *something* — never left as literal
    // "{{...}}" text in what actually reaches the recipient — falling back to an empty string for
    // anything genuinely unavailable, except {{name}} which reads better as "Friend" than blank.
    // Must stay in sync with the frontend's personalizeMessage (message-sender/index.tsx), which
    // handles the same six placeholders for the immediate-send path.
    private function personalizeMessage(string $text, array $recipient, ?Company $company = null): string
    {
        $companyDetails = $company?->name
            ? ($company->website ? "{$company->name} ({$company->website})" : $company->name)
            : '';

        return str_replace(
            ['{{name}}', '{{phone}}', '{{date}}', '{{time}}', '{{company_details}}', '{{company_number}}'],
            [
                $recipient['name'] ?: 'Friend',
                $recipient['phone'] ?? '',
                now()->format('d M Y'),
                now()->format('h:i A'),
                $companyDetails,
                $company?->phone ?? '',
            ],
            $text
        );
    }

    private function buildSignature(string $phone): string
    {
        $sig = '';
        for ($i = 0; $i < strlen($phone); $i++) {
            $sig .= (ord($phone[$i]) % 2 === 0) ? "\u{200B}" : "\u{200C}";
        }
        return $sig;
    }
}
