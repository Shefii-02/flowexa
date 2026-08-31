<?php

namespace App\Modules\WaChat\Jobs;

use App\Modules\WaChat\Models\MessageSenderJob;
use App\Modules\WaChat\Models\WahaMessageLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProcessMessageSenderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries   = 1;
    public int $timeout = 3600;

    public function __construct(public int $jobId) {}

    public function handle(): void
    {
        $job = MessageSenderJob::find($this->jobId);
        if (!$job || in_array($job->status, ['stopped', 'completed', 'failed'])) return;

        $job->update(['status' => 'running', 'started_at' => $job->started_at ?? now()]);

        $wahaBase = rtrim(config('services.waha.base_url', env('WAHA_BASE_URL', 'http://localhost:3000')), '/');
        $wahaKey  = config('services.waha.api_key', env('WAHA_API_KEY', ''));
        $payload  = $job->message_payload ?? [];
        $recipients = $payload['recipients'] ?? [];
        $delayMs  = max((int)($job->delay_ms ?? 1000), 500);

        foreach ($recipients as $index => $recipient) {
            // Reload job to check for pause/stop
            $job->refresh();
            if (in_array($job->status, ['stopped', 'paused'])) break;

            $phone   = $recipient['phone'] ?? '';
            $name    = $recipient['name']  ?? '';
            $message = $this->personalizeMessage($payload['text'] ?? '', $recipient);

            // Append unique anti-spam signature if enabled
            if ($job->unique_signature) {
                $message .= $this->buildSignature($phone);
            }

            $logData = [
                'company_id'      => $job->company_id,
                'job_id'          => $job->id,
                'campaign_name'   => $job->campaign_name,
                'session_id'      => $job->session_id,
                'recipient_name'  => $name,
                'recipient_phone' => $phone,
                'recipient_type'  => str_ends_with($phone, '@g.us') ? 'group' : 'contact',
                'message_type'    => $payload['type'] ?? 'text',
                'status'          => 'pending',
            ];

            try {
                $wahaPayload = $this->buildWahaPayload($job->session_id, $phone, $message, $payload);
                $res = Http::withHeaders(['X-API-Key' => $wahaKey])
                    ->timeout(30)
                    ->post("{$wahaBase}/api/sendText", $wahaPayload);

                if ($res->successful()) {
                    $wahaId = $res->json('id') ?? null;
                    WahaMessageLog::create(array_merge($logData, [
                        'status'          => 'sent',
                        'waha_message_id' => $wahaId,
                        'sent_at'         => now(),
                    ]));
                    $job->increment('sent');
                } else {
                    WahaMessageLog::create(array_merge($logData, [
                        'status'        => 'failed',
                        'error_message' => $res->body(),
                    ]));
                    $job->increment('failed');
                }
            } catch (\Exception $e) {
                Log::error("ProcessMessageSenderJob #{$job->id}: " . $e->getMessage());
                WahaMessageLog::create(array_merge($logData, [
                    'status'        => 'failed',
                    'error_message' => $e->getMessage(),
                ]));
                $job->increment('failed');
            }

            // Delay between messages (convert ms to microseconds)
            if ($index < count($recipients) - 1) {
                usleep($delayMs * 1000);
            }
        }

        $job->refresh();
        if ($job->status === 'running') {
            $job->update(['status' => 'completed', 'completed_at' => now()]);
        }
    }

    private function personalizeMessage(string $text, array $recipient): string
    {
        return str_replace(
            ['{{name}}', '{{phone}}', '{{date}}', '{{time}}'],
            [$recipient['name'] ?? '', $recipient['phone'] ?? '', now()->format('d M Y'), now()->format('h:i A')],
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

    private function buildWahaPayload(string $session, string $phone, string $message, array $payload): array
    {
        $chatId = str_contains($phone, '@') ? $phone : $phone . '@c.us';
        return [
            'session' => $session,
            'chatId'  => $chatId,
            'text'    => $message,
        ];
    }
}
