<?php

namespace App\Modules\WaChat\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\WaChat\Models\MessageSenderJob;
use App\Modules\WaChat\Jobs\ProcessMessageSenderJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MessageSenderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $jobs = MessageSenderJob::where('company_id', auth()->user()->company_id)
            ->with('creator:id,name')
            ->orderBy('created_at', 'desc')
            ->paginate(20);
        return response()->json($jobs);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'campaign_name'   => 'nullable|string|max:200',
            'session_id'      => 'required|string|max:100',
            'type'            => 'required|in:personal,group,csv,label,from-chat,campaign',
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

        // Dispatch immediately if not scheduled
        if (!isset($data['scheduled_at'])) {
            dispatch(new ProcessMessageSenderJob($job->id));
        }

        return response()->json(['message' => 'Job created.', 'data' => $job], 201);
    }

    public function show(int $id): JsonResponse
    {
        $job = MessageSenderJob::where('company_id', auth()->user()->company_id)
            ->with(['messageLogs', 'creator:id,name'])
            ->findOrFail($id);
        return response()->json(['data' => $job]);
    }

    public function pause(int $id): JsonResponse
    {
        $job = MessageSenderJob::where('company_id', auth()->user()->company_id)
            ->where('status', 'running')->findOrFail($id);
        $job->update(['status' => 'paused']);
        return response()->json(['message' => 'Job paused.']);
    }

    public function resume(int $id): JsonResponse
    {
        $job = MessageSenderJob::where('company_id', auth()->user()->company_id)
            ->where('status', 'paused')->findOrFail($id);
        $job->update(['status' => 'running']);
        dispatch(new ProcessMessageSenderJob($job->id));
        return response()->json(['message' => 'Job resumed.']);
    }

    public function stop(int $id): JsonResponse
    {
        $job = MessageSenderJob::where('company_id', auth()->user()->company_id)
            ->whereIn('status', ['running', 'paused', 'scheduled'])->findOrFail($id);
        $job->update(['status' => 'stopped', 'completed_at' => now()]);
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
