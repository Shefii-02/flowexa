<?php

namespace App\Modules\WaChat\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\WaChat\Models\WaExportJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class WaExportController extends Controller
{
    private function wahaBase(): string
    {
        return rtrim(config('services.waha.base_url', env('WAHA_BASE_URL', 'http://localhost:3000')), '/');
    }

    private function wahaHeaders(): array
    {
        return ['X-API-Key' => config('services.waha.api_key', env('WAHA_API_KEY', ''))];
    }

    public function listJobs(): JsonResponse
    {
        $jobs = WaExportJob::where('company_id', auth()->user()->company_id)
            ->orderBy('created_at', 'desc')
            ->paginate(20);
        return response()->json($jobs);
    }

    public function exportChats(Request $request): JsonResponse
    {
        $data = $request->validate([
            'session_id' => 'required|string|max:100',
            'filters'    => 'nullable|array',
        ]);

        $job = WaExportJob::create([
            'company_id'  => auth()->user()->company_id,
            'created_by'  => auth()->id(),
            'export_type' => 'chat_list',
            'session_id'  => $data['session_id'],
            'filters'     => $data['filters'] ?? [],
            'status'      => 'processing',
        ]);

        try {
            $res = Http::withHeaders($this->wahaHeaders())
                ->get("{$this->wahaBase()}/api/contacts", ['session' => $data['session_id']]);
            $rows = $res->json() ?? [];
            $csv  = $this->buildCsv(['id', 'name', 'isGroup', 'lastMessage'], $rows);
            $path = $this->saveCsv($job->id, 'chats', $csv);
            $job->update(['status' => 'done', 'file_url' => Storage::disk('public')->url($path), 'row_count' => count($rows)]);
        } catch (\Exception $e) {
            $job->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
        }

        return response()->json(['message' => 'Export started.', 'data' => $job->fresh()]);
    }

    public function exportGroups(Request $request): JsonResponse
    {
        $data = $request->validate([
            'session_id'         => 'required|string|max:100',
            'include_participants' => 'boolean',
            'filters'            => 'nullable|array',
        ]);

        $type = ($data['include_participants'] ?? false) ? 'group_participants' : 'group_list';
        $job = WaExportJob::create([
            'company_id'  => auth()->user()->company_id,
            'created_by'  => auth()->id(),
            'export_type' => $type,
            'session_id'  => $data['session_id'],
            'filters'     => $data['filters'] ?? [],
            'status'      => 'processing',
        ]);

        try {
            $res = Http::withHeaders($this->wahaHeaders())
                ->get("{$this->wahaBase()}/api/contacts", ['session' => $data['session_id'], 'filter' => 'groups']);
            $rows = $res->json() ?? [];

            if ($type === 'group_participants') {
                $participants = [];
                foreach ($rows as $group) {
                    $gid  = $group['id'] ?? '';
                    $pRes = Http::withHeaders($this->wahaHeaders())
                        ->get("{$this->wahaBase()}/api/groups/{$gid}/participants", ['session' => $data['session_id']]);
                    foreach (($pRes->json() ?? []) as $p) {
                        $p['group_id']   = $gid;
                        $p['group_name'] = $group['name'] ?? '';
                        $participants[]  = $p;
                    }
                }
                $csv      = $this->buildCsv(['group_id', 'group_name', 'id', 'name', 'isAdmin'], $participants);
                $rowCount = count($participants);
            } else {
                $csv      = $this->buildCsv(['id', 'name', 'participantsCount'], $rows);
                $rowCount = count($rows);
            }

            $path = $this->saveCsv($job->id, 'groups', $csv);
            $job->update(['status' => 'done', 'file_url' => Storage::disk('public')->url($path), 'row_count' => $rowCount]);
        } catch (\Exception $e) {
            $job->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
        }

        return response()->json(['message' => 'Group export started.', 'data' => $job->fresh()]);
    }

    public function download(int $id): JsonResponse
    {
        $job = WaExportJob::where('company_id', auth()->user()->company_id)
            ->where('status', 'done')
            ->findOrFail($id);
        return response()->json(['file_url' => $job->file_url, 'row_count' => $job->row_count]);
    }

    private function buildCsv(array $headers, array $rows): string
    {
        $lines = [implode(',', $headers)];
        foreach ($rows as $row) {
            $line = [];
            foreach ($headers as $h) {
                $val    = $row[$h] ?? '';
                $line[] = '"' . str_replace('"', '""', is_array($val) ? json_encode($val) : (string)$val) . '"';
            }
            $lines[] = implode(',', $line);
        }
        return implode("\n", $lines);
    }

    private function saveCsv(int $jobId, string $type, string $csv): string
    {
        $path = "exports/{$jobId}_{$type}_" . now()->format('Ymd_His') . ".csv";
        Storage::disk('public')->put($path, $csv);
        return $path;
    }
}
