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

        $wahaBase   = rtrim(config('services.waha.base_url', env('WAHA_BASE_URL', 'http://localhost:3000')), '/');
        $wahaKey    = config('services.waha.api_key', env('WAHA_API_KEY', ''));
        $payload    = $job->message_payload ?? [];
        $recipients = $payload['recipients'] ?? [];
        $delayMs    = max((int)($job->delay_ms ?? 1000), 500);
        $msgType    = $payload['type'] ?? 'text';

        foreach ($recipients as $index => $recipient) {
            $job->refresh();
            if (in_array($job->status, ['stopped', 'paused'])) break;

            $phone   = $recipient['phone'] ?? '';
            $name    = $recipient['name']  ?? '';
            $chatId  = str_contains($phone, '@') ? $phone : $phone . '@c.us';

            $logBase = [
                'company_id'      => $job->company_id,
                'job_id'          => $job->id,
                'campaign_name'   => $job->campaign_name,
                'session_id'      => $job->session_id,
                'recipient_name'  => $name,
                'recipient_phone' => $phone,
                'recipient_type'  => str_ends_with($phone, '@g.us') ? 'group' : 'contact',
                'message_type'    => $msgType,
                'status'          => 'pending',
            ];

            try {
                if ($msgType === 'media' && !empty($payload['blocks'])) {
                    // Send each media block sequentially
                    $this->sendMediaBlocks(
                        $wahaBase, $wahaKey, $job->session_id,
                        $chatId, $payload['blocks'], $recipient,
                        $job->unique_signature ?? false, $logBase, $job
                    );
                } else {
                    // Plain text (or template body)
                    $message = $this->personalizeMessage($payload['text'] ?? '', $recipient);
                    if ($job->unique_signature) {
                        $message .= $this->buildSignature($phone);
                    }

                    // If there is a single media header, send it first
                    if (!empty($payload['header_type']) && $payload['header_type'] !== 'none' && !empty($payload['header_url'])) {
                        $this->sendSingleMedia(
                            $wahaBase, $wahaKey, $job->session_id,
                            $chatId, $payload['header_type'], $payload['header_url'],
                            $message
                        );
                        WahaMessageLog::create(array_merge($logBase, [
                            'status'  => 'sent',
                            'sent_at' => now(),
                        ]));
                        $job->increment('sent');
                    } else {
                        $res = Http::withHeaders(['X-API-Key' => $wahaKey])
                            ->timeout(30)
                            ->post("{$wahaBase}/api/sendText", [
                                'session' => $job->session_id,
                                'chatId'  => $chatId,
                                'text'    => $message,
                            ]);

                        if ($res->successful()) {
                            WahaMessageLog::create(array_merge($logBase, [
                                'status'          => 'sent',
                                'waha_message_id' => $res->json('id') ?? null,
                                'sent_at'         => now(),
                            ]));
                            $job->increment('sent');
                        } else {
                            WahaMessageLog::create(array_merge($logBase, [
                                'status'        => 'failed',
                                'error_message' => $res->body(),
                            ]));
                            $job->increment('failed');
                        }
                    }
                }
            } catch (\Exception $e) {
                Log::error("ProcessMessageSenderJob #{$job->id}: " . $e->getMessage());
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
            $job->update(['status' => 'completed', 'completed_at' => now()]);
        }
    }

    private function sendMediaBlocks(
        string $wahaBase, string $wahaKey, string $session,
        string $chatId, array $blocks, array $recipient,
        bool $uniqueSig, array $logBase, MessageSenderJob $job
    ): void {
        $phone = $recipient['phone'] ?? '';
        $sent  = false;

        foreach ($blocks as $i => $block) {
            $type = $block['type'] ?? 'text';

            if ($type === 'text') {
                $text = $this->personalizeMessage($block['text'] ?? '', $recipient);
                if ($uniqueSig && $i === 0) $text .= $this->buildSignature($phone);
                $res = Http::withHeaders(['X-API-Key' => $wahaKey])
                    ->timeout(30)
                    ->post("{$wahaBase}/api/sendText", [
                        'session' => $session,
                        'chatId'  => $chatId,
                        'text'    => $text,
                    ]);
                $sent = $res->successful();
            } else {
                $url  = $block['mediaUrl'] ?? $block['url'] ?? '';
                if (!$url) continue;

                $endpoint = match($type) {
                    'image'    => 'sendImage',
                    'video'    => 'sendVideo',
                    'audio'    => 'sendVoice',
                    'document' => 'sendDocument',
                    default    => 'sendImage',
                };

                $mediaPayload = [
                    'session' => $session,
                    'chatId'  => $chatId,
                    'file'    => ['url' => $url],
                ];

                if (!empty($block['caption'])) {
                    $mediaPayload['caption'] = $this->personalizeMessage($block['caption'], $recipient);
                }
                if (!empty($block['filename'])) {
                    $mediaPayload['filename'] = $block['filename'];
                }

                $res  = Http::withHeaders(['X-API-Key' => $wahaKey])
                    ->timeout(60)
                    ->post("{$wahaBase}/api/{$endpoint}", $mediaPayload);
                $sent = $res->successful();
            }

            // Small inter-block delay
            if ($i < count($blocks) - 1) {
                usleep(600_000);
            }
        }

        if ($sent) {
            WahaMessageLog::create(array_merge($logBase, [
                'status'  => 'sent',
                'sent_at' => now(),
            ]));
            $job->increment('sent');
        } else {
            WahaMessageLog::create(array_merge($logBase, [
                'status'        => 'failed',
                'error_message' => 'No blocks sent successfully.',
            ]));
            $job->increment('failed');
        }
    }

    private function sendSingleMedia(
        string $wahaBase, string $wahaKey, string $session,
        string $chatId, string $mediaType, string $url, string $caption = ''
    ): void {
        $endpoint = match($mediaType) {
            'image'    => 'sendImage',
            'video'    => 'sendVideo',
            'audio'    => 'sendVoice',
            'document' => 'sendDocument',
            default    => 'sendImage',
        };

        $body = [
            'session' => $session,
            'chatId'  => $chatId,
            'file'    => ['url' => $url],
        ];
        if ($caption) $body['caption'] = $caption;

        Http::withHeaders(['X-API-Key' => $wahaKey])->timeout(60)->post("{$wahaBase}/api/{$endpoint}", $body);
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
}
