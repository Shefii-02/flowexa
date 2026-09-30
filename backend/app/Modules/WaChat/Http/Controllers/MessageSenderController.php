<?php

namespace App\Modules\WaChat\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Modules\WaChat\Models\MessageSenderJob;
use App\Modules\WaChat\Jobs\ProcessMessageSenderJob;
use App\Modules\WaChat\Services\OpenWaMessageService;
use Illuminate\Http\Client\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class MessageSenderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // Not-yet-launched campaigns (pending/scheduled) surface first, soonest first — a
        // "pending" job has no scheduled_at of its own (it's queued for an immediate send that
        // just hasn't been picked up yet), so it sorts ahead of any explicitly future-dated
        // scheduled one within that pinned group. Everything already launched keeps the previous
        // newest-first ordering below them.
        // Not paginated: a 20-row cap meant that once 20+ pending/scheduled campaigns existed
        // (they sort first — see below), every already-finished one dropped off the list entirely
        // until enough of the pending ones finished to make room. The frontend has no "load more"
        // for this list, so a hard cap here was silently hiding history rather than paging it.
        $jobs = MessageSenderJob::where('company_id', auth()->user()->company_id)
            ->with(['creator:id,name', 'messageLogs', 'wahaSession:session_name,display_name,phone'])
            ->withCount('leads')
            ->orderByRaw("CASE WHEN status IN ('pending','scheduled') THEN 0 ELSE 1 END")
            ->orderByRaw('scheduled_at IS NULL DESC')
            ->orderBy('scheduled_at', 'asc')
            ->orderBy('created_at', 'desc')
            ->get();
        $jobs->transform(fn($job) => $this->withLiveLog($job));
        return response()->json(['data' => $jobs]);
    }

    public function store(Request $request): JsonResponse
    {
        // Rejects a session_id that doesn't belong to one of this company's current sessions —
        // e.g. "Duplicate" on a past campaign whose original session was since deleted and
        // recreated (a new gateway id, even under the same display name). Without this, the job
        // is created and dispatched anyway, and every single send in it fails at gateway level
        // with "API key not authorized for this session" — a confusing, all-recipients-failed
        // campaign instead of a clear error at creation time.
        $data = $request->validate([
            'campaign_name'   => 'nullable|string|max:200',
            'session_id'      => [
                'required', 'string', 'max:100',
                Rule::exists('waha_sessions', 'session_name')
                    ->where('company_id', auth()->user()->company_id),
            ],
            'type'            => 'required|in:personal,group,csv,label,chat,from-chat,lead,campaign',
            'total'           => 'required|integer|min:1',
            'delay_ms'        => 'integer|min:0',
            'unique_signature'=> 'boolean',
            'scheduled_at'    => 'nullable|date|after:now',
            'log'             => 'nullable|array',
            'message_payload' => 'nullable|array',
            'lead_created_from' => 'nullable|date',
            'lead_created_to'   => 'nullable|date|after_or_equal:lead_created_from',
        ], [
            'session_id.exists' => 'That session no longer exists — pick a currently active one.',
        ]);

        $job = MessageSenderJob::create(array_merge($data, [
            'company_id' => auth()->user()->company_id,
            'created_by' => auth()->id(),
            'status'     => isset($data['scheduled_at']) ? 'scheduled' : 'pending',
            'sent'       => 0,
            'failed'     => 0,
            'started_at' => isset($data['scheduled_at']) ? null : now(),
        ]));

        // Log::info("MessageSenderController: campaign #{$job->id} created", [
        //     'company_id'    => $job->company_id,
        //     'campaign_name' => $job->campaign_name,
        //     'type'          => $job->type,
        //     'total'         => $job->total,
        //     'scheduled_at'  => $job->scheduled_at,
        //     'status'        => $job->status,
        // ]);

        // Dispatch immediately if not scheduled
        if (!isset($data['scheduled_at'])) {
            Log::info("MessageSenderController: dispatching campaign #{$job->id} immediately");
            dispatch(new ProcessMessageSenderJob($job->id));
        }

        return response()->json(['message' => 'Job created.', 'data' => $job], 201);
    }

    // Sends the composer's current message to a single test number, instead of creating a
    // campaign — used by the "Test Run" control in the sender UI. Checks the session's live
    // status with the gateway right before sending (getSession), since the frontend's session
    // list is cached for up to 30s and a test send is exactly the moment a stale "ready" would
    // be misleading. Mirrors ProcessMessageSenderJob's per-recipient dispatch (dispatchSend/
    // sendMediaBlocks/personalizeMessage below), but for exactly one recipient and with no DB
    // job/log rows — a test send isn't a campaign and shouldn't appear in campaign history.
    public function testSend(Request $request): JsonResponse
    {
        $data = $request->validate([
            'session_id' => [
                'required', 'string', 'max:100',
                Rule::exists('waha_sessions', 'session_name')
                    ->where('company_id', auth()->user()->company_id),
            ],
            'phone'   => ['required', 'string', 'regex:/^[0-9]{7,15}$/'],
            'type'    => 'required|in:text,media,poll,location,contact,audio',
            'payload' => 'nullable|array',
        ], [
            'session_id.exists' => 'That session no longer exists — pick a currently active one.',
            'phone.regex'       => 'Enter a valid test phone number.',
        ]);

        $company = auth()->user()->company;
        $apiKey  = $company?->wa_chat_token ?? '';
        $wa      = new OpenWaMessageService();

        $statusRes  = $wa->getSession($data['session_id'], $apiKey);
        $liveStatus = $statusRes->successful() ? ($statusRes->json('status') ?? 'unknown') : 'unreachable';
        if ($liveStatus !== 'ready') {
            return response()->json([
                'message' => "Session isn't connected right now (status: {$liveStatus}).",
            ], 409);
        }

        $chatId    = "{$data['phone']}@c.us";
        $recipient = ['name' => 'Test', 'phone' => $data['phone']];

        try {
            // \Throwable, not \Exception — a PHP Error (e.g. a TypeError from a malformed payload)
            // previously fell through both this catch and Laravel's own JSON error handling all the
            // way to a bare "Internal server error" 500 with no indication of what actually broke.
            // Every real failure now reaches the user as a diagnosable 422 with the actual message.
            $res = $this->dispatchTestSend($wa, $data['session_id'], $apiKey, $chatId, $data['type'], $data['payload'] ?? [], $recipient, $company);
        } catch (\Throwable $e) {
            Log::error("MessageSenderController::testSend: uncaught {$e->getMessage()}", [
                'exception' => get_class($e),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
            ]);
            return response()->json(['message' => $e->getMessage() ?: 'Test send failed — see server logs.'], 422);
        }

        if (!$res->successful()) {
            Log::warning('MessageSenderController::testSend: gateway rejected send', [
                'status' => $res->status(), 'body' => $res->body(),
            ]);
            // The gateway doesn't always return a JSON {message: ...} body (e.g. it can 5xx with
            // plain text/HTML if it crashed trying to fetch the media url itself) — falling back to
            // a flat "Test send failed." then hid the one clue (its HTTP status) that the frontend
            // could otherwise show the user.
            return response()->json([
                'message' => $res->json('message') ?: "Test send failed (gateway returned HTTP {$res->status()}).",
            ], 422);
        }

        return response()->json(['message' => 'Test message sent.']);
    }

    private function dispatchTestSend(
        OpenWaMessageService $wa,
        string $sessionId,
        string $apiKey,
        string $chatId,
        string $type,
        array $payload,
        array $recipient,
        ?Company $company,
    ): Response {
        return match ($type) {
            'media'    => $this->sendTestMediaBlocks($wa, $sessionId, $apiKey, $chatId, $payload['blocks'] ?? [], $recipient, $company),
            'poll'     => $wa->sendPoll($sessionId, $apiKey, $chatId, $payload['question'] ?? '', $payload['options'] ?? []),
            'location' => $wa->sendLocation($sessionId, $apiKey, $chatId, (float)($payload['lat'] ?? 0), (float)($payload['lng'] ?? 0), $payload['name'] ?? null, $payload['address'] ?? null),
            'contact'  => $wa->sendContact($sessionId, $apiKey, $chatId, $payload['contactName'] ?? '', $payload['contactNumber'] ?? ''),
            'audio'    => $wa->sendMedia($sessionId, $apiKey, $chatId, 'audio', ['url' => $payload['url'] ?? '']),
            default    => $wa->sendText($sessionId, $apiKey, $chatId, $this->personalizeTestMessage($payload['text'] ?? '', $recipient, $company)),
        };
    }

    // Sends every block in order, same rule as ProcessMessageSenderJob::sendMediaBlocks: success
    // is judged by the LAST block, since that's what the recipient actually ends up seeing.
    private function sendTestMediaBlocks(
        OpenWaMessageService $wa,
        string $sessionId,
        string $apiKey,
        string $chatId,
        array $blocks,
        array $recipient,
        ?Company $company,
    ): Response {
        $last = null;
        foreach ($blocks as $i => $block) {
            $type = $block['type'] ?? 'text';
            if ($type === 'text') {
                $last = $wa->sendText($sessionId, $apiKey, $chatId, $this->personalizeTestMessage($block['text'] ?? '', $recipient, $company));
            } else {
                $url = $block['mediaUrl'] ?? $block['url'] ?? '';
                if (!$url) continue;
                $mediaPayload = ['url' => $url];
                if (!empty($block['caption'])) $mediaPayload['caption'] = $this->personalizeTestMessage($block['caption'], $recipient, $company);
                if (!empty($block['filename'])) $mediaPayload['filename'] = $block['filename'];
                $last = $wa->sendMedia($sessionId, $apiKey, $chatId, $type, $mediaPayload);
            }
            if ($i < count($blocks) - 1) usleep(600_000);
        }
        if (!$last) {
            throw new \RuntimeException('No media block had a sendable url or text.');
        }
        return $last;
    }

    // Same six placeholders as ProcessMessageSenderJob::personalizeMessage — kept as a separate
    // copy (not shared) since a test send always uses a fixed 'Test' recipient, not a real one.
    private function personalizeTestMessage(string $text, array $recipient, ?Company $company = null): string
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

    public function show(int $id): JsonResponse
    {
        $job = MessageSenderJob::where('company_id', auth()->user()->company_id)
            ->with(['messageLogs', 'creator:id,name', 'wahaSession:session_name,display_name,phone'])
            ->withCount('leads')
            ->findOrFail($id);
        return response()->json(['data' => $this->withLiveLog($job)]);
    }

    // The `log` column is only ever the pre-send recipient snapshot store() was given — it never
    // gets updated as messages actually go out, so every recipient would show "pending" forever.
    // Real per-recipient outcomes land in waha_message_logs as the job runs; once any exist, they
    // replace the static snapshot so the history table and delivery-details drawer show what
    // actually happened instead of what was about to be attempted.
    private function withLiveLog(MessageSenderJob $job): MessageSenderJob
    {
        if ($job->messageLogs->isNotEmpty()) {
            $job->setAttribute('log', $job->messageLogs->map(fn($l) => [
                'recipient_name' => $l->recipient_name,
                'phone'          => $l->recipient_phone,
                'status'         => $l->status,
                'sent_at'        => $l->sent_at?->toIso8601String(),
                'error'          => $this->userFacingError($l->error_message),
            ])->all());
        }
        $job->makeHidden(['messageLogs', 'wahaSession']);
        return $job;
    }

    // Temporarily hides the raw gateway 401 body ({"message":"API key not authorized for this
    // session",...}) from the recipient log — it's an internal key/session-scoping issue (see
    // ProcessMessageSenderJob's stale-session guard), not something the user can act on, and the
    // raw JSON reads as broken rather than as a transient delivery hiccup. error_message in the DB
    // (and the Laravel log) still keeps the raw text for diagnosis; only this display copy changes.
    private function userFacingError(?string $raw): ?string
    {
        if ($raw && str_contains($raw, 'not authorized for this session')) {
            return 'Temporary delivery issue — please retry this recipient.';
        }
        return $raw;
    }

    public function pause(int $id): JsonResponse
    {
        $job = MessageSenderJob::where('company_id', auth()->user()->company_id)
            ->where('status', 'running')->findOrFail($id);
        $job->update(['status' => 'paused']);
        Log::info("MessageSenderController: campaign #{$id} paused");
        return response()->json(['message' => 'Job paused.']);
    }

    public function resume(int $id): JsonResponse
    {
        $job = MessageSenderJob::where('company_id', auth()->user()->company_id)
            ->where('status', 'paused')->findOrFail($id);
        $job->update(['status' => 'running']);
        Log::info("MessageSenderController: campaign #{$id} resumed, dispatching");
        dispatch(new ProcessMessageSenderJob($job->id));
        return response()->json(['message' => 'Job resumed.']);
    }

    // Manually start a job that's waiting to go out — either "pending" (created but never picked
    // up by a worker, e.g. after a queue restart) or "scheduled" for later. ProcessMessageSenderJob
    // sets its own "running" status once it actually starts, same as the automatic dispatch paths
    // (store() for an immediate send, ProcessScheduledMessages for one whose time arrived).
    public function launch(int $id): JsonResponse
    {
        $job = MessageSenderJob::where('company_id', auth()->user()->company_id)
            ->whereIn('status', ['pending', 'scheduled'])->findOrFail($id);
        $job->update(['status' => 'running', 'started_at' => $job->started_at ?? now()]);
        Log::info("MessageSenderController: campaign #{$id} launched, dispatching");
        dispatch(new ProcessMessageSenderJob($job->id));
        return response()->json(['message' => 'Job launched.']);
    }

    public function stop(int $id): JsonResponse
    {
        $job = MessageSenderJob::where('company_id', auth()->user()->company_id)
            ->whereIn('status', ['running', 'paused', 'scheduled', 'pending'])->findOrFail($id);
        $job->update(['status' => 'stopped', 'completed_at' => now()]);
        Log::info("MessageSenderController: campaign #{$id} stopped");
        return response()->json(['message' => 'Job stopped.']);
    }

    public function destroy(int $id): JsonResponse
    {
        $job = MessageSenderJob::where('company_id', auth()->user()->company_id)->findOrFail($id);
        $job->delete();
        return response()->json(['message' => 'Job deleted.']);
    }

    public function stats(): JsonResponse
    {
        $companyId = auth()->user()->company_id;
        $stats = MessageSenderJob::where('company_id', $companyId)
            ->selectRaw('SUM(sent) as total_sent, SUM(failed) as total_failed, COUNT(*) as total_jobs')
            ->first();
        return response()->json(['data' => $stats]);
    }
}
