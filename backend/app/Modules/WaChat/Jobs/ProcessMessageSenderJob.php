<?php

namespace App\Modules\WaChat\Jobs;

use App\Models\Company;
use App\Modules\WaChat\Models\MessageSenderJob;
use App\Modules\WaChat\Models\WahaMessageLog;
use App\Modules\WaChat\Models\WahaSession;
use App\Modules\WaChat\Services\OpenWaMessageService;
use App\Modules\WaChat\Services\WaChatTokenService;
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
    private const MAX_ATTEMPTS = 3; // sends per recipient (first try + auto-fix/backoff retries)

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
        // A resumed job, or one re-run after a worker crash, starts from the top of the list —
        // skip anyone this job already delivered to so they never get the message twice.
        $alreadySent = WahaMessageLog::where('job_id', $job->id)->where('status', 'sent')
            ->pluck('recipient_phone')
            ->map(fn ($p) => str_contains((string) $p, '@') ? strtolower($p) : preg_replace('/\D/', '', (string) $p))
            ->flip();
        $recipients = array_values(array_filter($recipients, function ($r) use ($alreadySent) {
            $phone = (string) ($r['phone'] ?? '');
            $key   = str_contains($phone, '@') ? strtolower($phone) : preg_replace('/\D/', '', $phone);
            return !isset($alreadySent[$key]);
        }));
        $logMessageType = $this->resolveLogMessageType($payload['type'] ?? 'text', $payload['blocks'] ?? []);

        // A scheduled campaign can sit queued for hours/days. If its session was deleted and
        // recreated in the meantime (a new gateway session id, even under the same display name
        // — see the creation-time check in MessageSenderController::store()), job.session_id is
        // now stale and no amount of allowlist re-sync below will ever make it valid again — the
        // sync below only re-pushes sessions the company CURRENTLY owns, so it can never add back
        // one that's gone. Catch that once, up front, instead of failing every recipient
        // one-by-one with the same unhelpful 401.
        if ($company && !WahaSession::where('company_id', $company->id)->where('session_name', $job->session_id)->exists()) {
            Log::warning("ProcessMessageSenderJob #{$job->id}: session {$job->session_id} no longer belongs to company {$job->company_id} — it was deleted/recreated since this campaign was created or scheduled");
            foreach ($recipients as $recipient) {
                WahaMessageLog::create([
                    'company_id'      => $job->company_id,
                    'job_id'          => $job->id,
                    'campaign_name'   => $job->campaign_name,
                    'session_id'      => $job->session_id,
                    'recipient_name'  => $recipient['name'] ?? '',
                    'recipient_phone' => $recipient['phone'] ?? '',
                    'recipient_type'  => $job->type,
                    'message_type'    => $logMessageType,
                    'status'          => 'failed',
                    'error_message'   => 'Session no longer exists — it was deleted or recreated after this campaign was created/scheduled.',
                ]);
            }
            $job->update(['status' => 'done', 'failed' => count($recipients), 'completed_at' => now()]);
            return;
        }

        // Self-heal: the gateway key's allowedSessions list is a separate, best-effort PUT
        // (WaChatTokenService::syncSessions) that runs whenever a session is created/deleted —
        // if that push was ever missed (a transient gateway timeout, a session created before the
        // company had a key yet, ...) the job's own session can silently fall out of the key's
        // allowlist and every send fails with "API key not authorized for this session" even
        // though the key itself is valid. Re-push right before sending so a campaign never fails
        // for a gap this cheap to close. $resyncedForAuth (below) covers the same drift happening
        // again mid-run, e.g. a session recreated while a long campaign is still sending.
        if ($company) {
            app(WaChatTokenService::class)->syncSessions($company);
        }
        $resyncedForAuth = false;
        $delayMs    = max((int)($job->delay_ms ?? 1000), 500);
        $msgType    = $payload['type'] ?? 'text';

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
            // The gateway rejects ids like "+9180…@c.us" (400) — a plain number must be digits only.
            $chatId  = str_contains($phone, '@') ? $phone : preg_replace('/\D+/', '', $phone) . '@c.us';

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

            // ── Send with automatic diagnosis + recovery ───────────────────────────────
            // Each failure is classified and, where a fix exists, applied and retried (up to
            // MAX_ATTEMPTS tries in total):
            //   transient (timeout / 429 / 5xx)  -> wait, retry
            //   401 (key / allowlist drift)      -> refresh token + re-sync sessions, retry
            //   bad number format                -> re-clean the number (digits only, drop leading 0s), retry
            //   not on WhatsApp / still invalid  -> permanent: stop now, tell the user why
            // The final outcome records how many tries it took and the diagnosed reason.
            $attempts = 0;
            $res      = null;
            $errType  = null;
            $rawError = null;
            $renumbered = false;

            while ($attempts < self::MAX_ATTEMPTS) {
                $attempts++;
                try {
                    $res = $this->dispatchSend($wa, $job, $apiKey, $chatId, $msgType, $payload, $recipient, $company);
                } catch (\Throwable $e) {
                    $res      = null;
                    $rawError = $e->getMessage();
                    $errType  = $e instanceof \Illuminate\Http\Client\ConnectionException ? 'network' : 'error';
                    Log::warning("ProcessMessageSenderJob #{$job->id}: attempt {$attempts} threw for {$chatId}: {$rawError}");
                    if ($errType === 'network' && $attempts < self::MAX_ATTEMPTS) { sleep($attempts * 3); continue; }
                    break;
                }

                if ($res->successful()) { $errType = null; $rawError = null; break; }

                $status   = $res->status();
                $rawError = $res->body();
                $errType  = $this->classifyFailure($status, $rawError);
                Log::warning("ProcessMessageSenderJob #{$job->id}: attempt {$attempts} failed for {$chatId} ({$errType})", ['status' => $status, 'body' => $rawError]);

                if ($status === 401 && !$resyncedForAuth && $company) {
                    // The allowlist drifted, or the key was regenerated mid-campaign — refresh both.
                    $resyncedForAuth = true;
                    $company = $company->fresh() ?? $company;
                    $apiKey  = $company->wa_chat_token ?: $apiKey;
                    app(WaChatTokenService::class)->syncSessions($company);
                    continue;
                }

                if ($errType === 'network' && $attempts < self::MAX_ATTEMPTS) { sleep($attempts * 3); continue; }

                if ($errType === 'bad_number' && !$renumbered && !str_contains($phone, '@')) {
                    // Auto-fix: international "00…" prefix / trunk-zero left in the number.
                    $fixed = ltrim(preg_replace('/\D+/', '', $phone), '0');
                    if ($fixed !== '' && $fixed . '@c.us' !== $chatId) {
                        $renumbered = true;
                        $chatId     = $fixed . '@c.us';
                        continue;
                    }
                }

                break; // permanent (not on WhatsApp, still a bad number, or out of tries)
            }

            if ($res && $res->successful()) {
                Log::info("ProcessMessageSenderJob #{$job->id}: sent to {$chatId} (attempt {$attempts})", ['status' => $res->status()]);
                WahaMessageLog::create(array_merge($logBase, [
                    'status'          => 'sent',
                    'attempts'        => $attempts,
                    'waha_message_id' => $res->json('messageId') ?? null,
                    'sent_at'         => now(),
                ]));
                $job->increment('sent');
            } else {
                $reason = $this->friendlyFailure($errType, $attempts);
                Log::warning("ProcessMessageSenderJob #{$job->id}: gave up on {$chatId} after {$attempts} try(s): {$errType}");
                WahaMessageLog::create(array_merge($logBase, [
                    'status'        => 'failed',
                    'attempts'      => $attempts,
                    'error_type'    => $errType ?? 'error',
                    'error_message' => $reason . ($rawError ? ' — ' . mb_substr($rawError, 0, 300) : ''),
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

    // Decides what kind of failure a gateway response is, so the loop knows whether a retry or an
    // automatic fix can possibly help.
    private function classifyFailure(int $status, string $body): string
    {
        $b = strtolower($body);
        // "must include an id property" is open-wa/WhatsApp Web failing to resolve a chat for a
        // number that has no WhatsApp account — it is not a formatting problem.
        if (preg_match('/not (a )?(registered|whatsapp)|no whatsapp|not exist|isn.t on whatsapp|invalid (wid|jid)|must include an id property/', $b)) {
            return 'not_on_whatsapp';
        }
        if ($status === 401 || $status === 403) return 'auth';
        if (in_array($status, [408, 425, 429], true) || $status >= 500) return 'network';
        if (in_array($status, [400, 404, 422], true)) return 'bad_number';
        return 'error';
    }

    private function friendlyFailure(?string $type, int $attempts): string
    {
        $tries = $attempts . ' ' . ($attempts === 1 ? 'try' : 'tries');
        return match ($type) {
            'not_on_whatsapp' => "Number is not on WhatsApp, or has no chat that can be opened — check the number, edit it and resend ({$tries})",
            'bad_number'      => "Gateway rejected this number — check the country code and digits, edit it and resend ({$tries})",
            'network'         => "Gateway/network problem, still failing after {$tries} — resend later",
            'auth'            => "Session authorisation problem after {$tries} — reconnect the session and resend",
            default           => "Send failed after {$tries}",
        };
    }

    // If the worker dies or the job throws outside the per-recipient try/catch, the row would stay
    // "running" forever while recipients sit pending. Flip it to "paused" so the UI stops lying
    // and the user can Resume (which now skips everyone already sent).
    public function failed(\Throwable $e): void
    {
        Log::error("ProcessMessageSenderJob #{$this->jobId}: job failed — {$e->getMessage()}");
        MessageSenderJob::where('id', $this->jobId)->where('status', 'running')->update(['status' => 'paused']);
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

    // Builds the right gateway call for the recipient's message type. Extracted out of handle()'s
    // loop (rather than a closure captured over $apiKey) so the 401 retry path there can call it
    // again with a freshly re-read $apiKey — a closure would keep using the stale one it was
    // defined with.
    private function dispatchSend(
        OpenWaMessageService $wa,
        MessageSenderJob $job,
        string $apiKey,
        string $chatId,
        string $msgType,
        array $payload,
        array $recipient,
        ?Company $company,
    ): Response {
        return match ($msgType) {
            'media' => $this->sendMediaBlocks($wa, $job->session_id, $apiKey, $chatId, $payload['blocks'] ?? [], $recipient, $job->unique_signature ?? false, $company),
            'poll' => $wa->sendPoll($job->session_id, $apiKey, $chatId, $payload['question'] ?? '', $payload['options'] ?? []),
            'location' => $wa->sendLocation($job->session_id, $apiKey, $chatId, (float)($payload['lat'] ?? 0), (float)($payload['lng'] ?? 0), $payload['name'] ?? null, $payload['address'] ?? null),
            'contact' => $wa->sendContact($job->session_id, $apiKey, $chatId, $payload['contactName'] ?? '', $payload['contactNumber'] ?? ''),
            'audio' => $wa->sendMediaFromUrl($job->session_id, $apiKey, $chatId, 'audio', (string) ($payload['url'] ?? '')),
            default => $this->sendText($wa, $job->session_id, $apiKey, $chatId, $payload['text'] ?? '', $recipient, $job->unique_signature ?? false, $company),
        };
    }

    private function sendText(
        OpenWaMessageService $wa,
        string $sessionId,
        string $apiKey,
        string $chatId,
        string $text,
        array $recipient,
        bool $uniqueSig,
        ?Company $company,
    ): Response {
        $message = $this->personalizeMessage($text, $recipient, $company);
        if ($uniqueSig) $message .= $this->buildSignature($recipient['phone'] ?? '');
        return $wa->sendText($sessionId, $apiKey, $chatId, $message);
    }

    // Sends every block in order and reports success only if the LAST one succeeds — the trailing
    // block is what a recipient actually sees at the end of the sequence, so that is the
    // meaningful pass/fail signal for this recipient's row in the log.
    private function sendMediaBlocks(
        OpenWaMessageService $wa,
        string $sessionId,
        string $apiKey,
        string $chatId,
        array $blocks,
        array $recipient,
        bool $uniqueSig,
        ?Company $company,
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

                $mediaPayload = [];
                if (!empty($block['caption'])) {
                    $mediaPayload['caption'] = $this->personalizeMessage($block['caption'], $recipient, $company);
                }
                if (!empty($block['filename'])) {
                    $mediaPayload['filename'] = $block['filename'];
                }
                $last = $wa->sendMediaFromUrl($sessionId, $apiKey, $chatId, $type, $url, $mediaPayload);
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
