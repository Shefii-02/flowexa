<?php

namespace App\Modules\WaChat\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\WaChat\Models\MessageSenderJob;
use App\Modules\WaChat\Jobs\ProcessMessageSenderJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

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
        $data = $request->validate([
            'campaign_name'   => 'nullable|string|max:200',
            'session_id'      => 'required|string|max:100',
            'type'            => 'required|in:personal,group,csv,label,chat,from-chat,campaign',
            'total'           => 'required|integer|min:1',
            'delay_ms'        => 'integer|min:0',
            'unique_signature'=> 'boolean',
            'scheduled_at'    => 'nullable|date|after:now',
            'log'             => 'nullable|array',
            'message_payload' => 'nullable|array',
        ]);

        $job = MessageSenderJob::create(array_merge($data, [
            'company_id' => auth()->user()->company_id,
            'created_by' => auth()->id(),
            'status'     => isset($data['scheduled_at']) ? 'scheduled' : 'pending',
            'sent'       => 0,
            'failed'     => 0,
            'started_at' => isset($data['scheduled_at']) ? null : now(),
        ]));

        Log::info("MessageSenderController: campaign #{$job->id} created", [
            'company_id'    => $job->company_id,
            'campaign_name' => $job->campaign_name,
            'type'          => $job->type,
            'total'         => $job->total,
            'scheduled_at'  => $job->scheduled_at,
            'status'        => $job->status,
        ]);

        // Dispatch immediately if not scheduled
        if (!isset($data['scheduled_at'])) {
            Log::info("MessageSenderController: dispatching campaign #{$job->id} immediately");
            dispatch(new ProcessMessageSenderJob($job->id));
        }

        return response()->json(['message' => 'Job created.', 'data' => $job], 201);
    }

    public function show(int $id): JsonResponse
    {
        $job = MessageSenderJob::where('company_id', auth()->user()->company_id)
            ->with(['messageLogs', 'creator:id,name', 'wahaSession:session_name,display_name,phone'])
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
                'error'          => $l->error_message,
            ])->all());
        }
        $job->makeHidden(['messageLogs', 'wahaSession']);
        return $job;
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
