<?php

namespace App\Modules\WaChat\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\WaChat\Jobs\RunWaExport;
use App\Modules\WaChat\Models\WaExportJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * CSV exports pulled from the WhatsApp Chat (open-wa) gateway. The controller
 * only records the job and queues {@see RunWaExport}; the front-end polls
 * GET /wa-export until the row flips to "done" / "failed", so a slow gateway
 * never blocks the request or leaves a job stuck "processing".
 */
class WaExportController extends Controller
{
    public function listJobs(): JsonResponse
    {
        $jobs = WaExportJob::where('company_id', auth()->user()->company_id)
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json($jobs);
    }

    /** Export the session's chat list. */
    public function exportChats(Request $request): JsonResponse
    {
        return $this->queue('chat_list', $request->validate([
            'session_id' => 'required|string|max:100',
            'filters'    => 'nullable|array',
        ]));
    }

    /** Export the session's saved (phone-book) contacts. */
    public function exportContacts(Request $request): JsonResponse
    {
        return $this->queue('contact_list', $request->validate([
            'session_id' => 'required|string|max:100',
            'filters'    => 'nullable|array',
        ]));
    }

    /** Export groups — optionally with one row per participant. */
    public function exportGroups(Request $request): JsonResponse
    {
        $data = $request->validate([
            'session_id'           => 'required|string|max:100',
            'include_participants'  => 'boolean',
            'filters'              => 'nullable|array',
        ]);

        $withParticipants = (bool) ($data['include_participants'] ?? false);
        $filters = array_merge($data['filters'] ?? [], ['include_participants' => $withParticipants]);

        return $this->queue($withParticipants ? 'group_participants' : 'group_list', [
            'session_id' => $data['session_id'],
            'filters'    => $filters,
        ]);
    }

    public function download(int $id): JsonResponse
    {
        $job = WaExportJob::where('company_id', auth()->user()->company_id)
            ->where('status', 'done')
            ->findOrFail($id);

        return response()->json(['file_url' => $job->file_url, 'row_count' => $job->row_count]);
    }

    /** Delete an export job and its CSV file. */
    public function destroy(int $id): JsonResponse
    {
        $job = WaExportJob::where('company_id', auth()->user()->company_id)->findOrFail($id);

        if ($job->file_url) {
            $file = basename(parse_url($job->file_url, PHP_URL_PATH) ?: '');
            if ($file !== '') {
                Storage::disk('public')->delete("exports/{$file}");
            }
        }

        $job->delete();

        return response()->json(['message' => 'Export deleted.']);
    }

    private function queue(string $type, array $data): JsonResponse
    {
        $companyId = auth()->user()->company_id;

        // One export at a time per company — return the in-flight one rather than
        // stacking jobs (and keeping the front-end polling longer than needed).
        $running = WaExportJob::where('company_id', $companyId)
            ->whereIn('status', ['pending', 'processing'])
            ->where('created_at', '>', now()->subMinutes(15))
            ->latest()
            ->first();

        if ($running) {
            return response()->json([
                'message' => 'An export is already running — wait for it to finish.',
                'data'    => $running,
            ], 409);
        }

        $job = WaExportJob::create([
            'company_id'  => $companyId,
            'created_by'  => auth()->id(),
            'export_type' => $type,
            'session_id'  => $data['session_id'],
            'filters'     => $data['filters'] ?? [],
            'status'      => 'processing',
        ]);

        RunWaExport::dispatch($job->id);

        return response()->json(['message' => 'Export queued.', 'data' => $job], 202);
    }
}
